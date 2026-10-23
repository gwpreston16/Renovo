<?php

declare(strict_types=1);

namespace App\Service\Backup\Destination;

/**
 * Test connection, the same for every destination: write a small marker,
 * read it back, compare, delete it. Each failure names its step.
 */
trait RoundTripTest
{
    public function test(): void
    {
        $work = sys_get_temp_dir() . '/renovo-destination-test-' . bin2hex(random_bytes(6));
        $marker = $work . '.marker';
        $back = $work . '.back';
        $contents = 'Renovo connection test ' . bin2hex(random_bytes(8)) . "\n";

        try {
            file_put_contents($marker, $contents);

            try {
                $this->put($marker, self::MARKER, hash('sha256', $contents), strlen($contents));
            } catch (DestinationException $exception) {
                throw $exception->step === DestinationException::STEP_CONNECT
                    ? $exception
                    : $exception->withStep(DestinationException::STEP_WRITE);
            }

            try {
                $this->get(self::MARKER, $back);
            } catch (DestinationException $exception) {
                throw $exception->withStep(DestinationException::STEP_READ);
            }

            if (@file_get_contents($back) !== $contents) {
                throw new DestinationException(
                    DestinationException::STEP_VERIFY,
                    'mismatch',
                    'The marker read back is not the one written.',
                );
            }

            try {
                $this->delete(self::MARKER);
            } catch (DestinationException $exception) {
                throw $exception->withStep(DestinationException::STEP_DELETE);
            }
        } finally {
            @unlink($marker);
            @unlink($back);
        }
    }
}
