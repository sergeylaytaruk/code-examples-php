<?php

namespace Components\Admissions;

use Components\NDatabase\NDatabase;

class AdmissionTime
{
    private $admissionStartDate;
    private $admissionEndDate;
    private $admissionLength;
    private $clinicId;
    private $doctorId;
    private $admissionId;
    private $dtFrom;
    private $dtTo;
    private $dtLength;
    public function __construct($date, $length, $clinicId, $userId, $admissionId = 0)
    {
        $this->admissionStartDate = $date;
        $this->admissionLength = $length;
        $this->clinicId = $clinicId;
        $this->doctorId = $userId;
        $this->admissionId = $admissionId;
    }

    public function isAllowedAdmissionTime()
    {
        $this->setStartEndDateTime();
        if ($this->isBusyTime()) {
            return $this->getAdmissionTimeOffer();
        } else {
            return [
                'result' => true
            ];
        }
    }

    private function setStartEndDateTime()
    {
        $this->admissionEndDate = NDatabase::getOne(
            "SELECT DATE_ADD(:dateFrom, INTERVAL TIME_TO_SEC(:length) SECOND) AS date_to",
            [':dateFrom' => $this->admissionStartDate, ':length' => $this->admissionLength]
        );
        $this->dtFrom = NDatabase::getOne(
            "SELECT DATE_FORMAT(:date, '%Y-%m-%d %H:%i')",
            [':date' => $this->admissionStartDate]
        );
        $this->dtTo = NDatabase::getOne(
            "SELECT DATE_FORMAT(:date, '%Y-%m-%d %H:%i')",
            [':date' => $this->admissionEndDate]
        );
        $this->dtLength = NDatabase::getOne(
            "SELECT TIME_TO_SEC(:length)",
            [':length' => $this->admissionLength]
        );
    }

    private function isBusyTime(): bool
    {
        $cnt = (int) NDatabase::getOne(
            "SELECT
                COUNT(1)
            FROM admission a
            WHERE a.clinic_id = :clinicId
            AND a.user_id = :doctorId
            AND a.id <> :admissionId
            AND DATE(a.admission_date) = DATE(:admissionStartDate)
            AND a.status NOT IN('accepted','deleted','delayed')
            AND (
                UNIX_TIMESTAMP(:admissionStartDate) >= UNIX_TIMESTAMP(a.admission_date)
                AND
                UNIX_TIMESTAMP(:admissionStartDate) < UNIX_TIMESTAMP(DATE_ADD(a.admission_date, INTERVAL TIME_TO_SEC(a.admission_length)SECOND))
            )
            ",
            [
                ':clinicId' => $this->clinicId,
                ':doctorId' => $this->doctorId,
                ':admissionId' => $this->admissionId,
                ':admissionStartDate' => $this->admissionStartDate,
            ]
        );
        return $cnt > 0;
    }

    private function getTimeSheets(): array
    {
        $timeSheets = NDatabase::getAllAssoc(
            "SELECT
                DATE_FORMAT(
                GREATEST(t.`begin_datetime`, NOW()), '%Y-%m-%d %H:%i:00'
                ) AS date_from, 
                t.`end_datetime` AS date_to
            FROM timesheet t
            JOIN timesheet_types tt ON tt.`id` = t.`type` AND tt.`is_working_hours` = 1
            WHERE t.clinic_id = :clinicId
                AND t.doctor_id = :userId
                AND (DATE(t.`begin_datetime`) = DATE(:date) OR DATE(t.`end_datetime`) = DATE(:date))
                AND t.`end_datetime` > NOW()",
            [
                ':clinicId' => $this->clinicId,
                ':userId' => $this->doctorId,
                ':date' => $this->dtFrom,
            ]
        );

        if (sizeof($timeSheets) == 0) {
            $timeSheets = NDatabase::getAllAssoc("SELECT NOW() AS date_from, NOW() + INTERVAL 1 DAY AS date_to");
        }
        return $timeSheets;
    }

    private function getAdmissions($timeSheetDateFrom, $timeSheetDateTo): array
    {
        return NDatabase::getAllAssoc(
            "SELECT
                        DATE_FORMAT(GREATEST(a.`admission_date`, NOW()), '%Y-%m-%d %H:%i:00') AS date_from, 
                        a.`admission_date` + INTERVAL TIME_TO_SEC(a.`admission_length`) SECOND AS date_to
                    FROM admission a
                    WHERE a.`user_id` = :userId
                        AND a.`clinic_id` = :clinicId
                        AND a.`admission_date` < :dateTo 
                        AND a.`admission_date` + INTERVAL TIME_TO_SEC(a.`admission_length`) SECOND > :dateFrom
                        AND a.id <> :admissionId
                        AND a.status NOT IN('accepted','deleted','delayed')
                    ORDER BY a.admission_date, a.admission_length;
                ",
            [
                ':userId' => $this->doctorId,
                ':clinicId' => $this->clinicId,
                ':admissionId' => $this->admissionId,
                ':dateFrom' => $timeSheetDateFrom,
                ':dateTo' => $timeSheetDateTo
            ]
        );
    }

    private function getAdmissionTimeOffer(): array
    {
        $timeSheets = $this->getTimeSheets();
        $dtCurrentFrom = null;
        $dtCurrentTo = null;

        foreach ($timeSheets as $timesheet) {
            $dtTimesheetFrom = new \DateTime($timesheet['date_from']);
            $dtTimesheetTo = new \DateTime($timesheet['date_to']);
            $dtCurrentFrom = $dtTimesheetFrom;
            $dtCurrentTo = $dtTimesheetTo;
            if ($dtTimesheetTo->getTimestamp() - $dtTimesheetFrom->getTimestamp() < $this->dtLength) {
                continue;
            }
            $admissions = $this->getAdmissions($timesheet['date_from'], $timesheet['date_to']);

            foreach ($admissions as $admission) {
                $dtAdmissionFrom = new \DateTime($admission['date_from']);
                $dtAdmissionTo = new \DateTime($admission['date_to']);
                $dtCurrentTo = $dtAdmissionFrom;
                $length = $dtCurrentTo->getTimestamp() - $dtCurrentFrom->getTimestamp();
                if ($length < $this->dtLength) {
                    $dtCurrentFrom = $dtAdmissionTo;
                    $dtCurrentTo = $dtTimesheetTo;
                } else {
                    $dtCurrentTo->setTimestamp($dtCurrentFrom->getTimestamp() + $this->dtLength);
                    return [
                        'result' => false,
                        'clear_dt_from' => $dtCurrentFrom->format('Y-m-d H:i:s'),
                        'clear_dt_to' => $dtCurrentTo->format('Y-m-d H:i:s'),
                    ];
                }
            }
            $length = $dtCurrentTo->getTimestamp() - $dtCurrentFrom->getTimestamp();
            if ($length >= $this->dtLength) {
                $dtCurrentTo->setTimestamp($dtCurrentFrom->getTimestamp() + $this->dtLength);
                return [
                    'result' => false,
                    'clear_dt_from' => $dtCurrentFrom->format('Y-m-d H:i:s'),
                    'clear_dt_to' => $dtCurrentTo->format('Y-m-d H:i:s'),
                ];
            }
        }
        return [
            'result' => false
        ];
    }
}
