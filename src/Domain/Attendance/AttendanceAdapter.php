<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/**
 * Replaceable reader contract: only already-normalized AttendanceMark objects
 * may cross into Condor. Integrations must discard biometric/device raw data
 * outside Condor and must not call this contract until privacy is approved.
 *
 * No real reader, network transport or credential wiring exists in this slice.
 */
interface AttendanceAdapter
{
    public function sourceKey(): string;

    /** @return iterable<AttendanceMark> */
    public function normalizedMarks(): iterable;
}
