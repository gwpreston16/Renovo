<?php

declare(strict_types=1);

namespace App\Service\Backup\Destination;

/**
 * Somewhere off this server that holds copies of instance backups (Phase 39).
 *
 * The seam for every kind of destination, as `Notifier` is for channels: a
 * new one (SFTP, a cloud drive) implements this and is added to
 * `BackupDestinationFactory`; nothing that sends, prunes or rehearses changes.
 *
 * A destination only ever holds what it is given — an encrypted backup — and
 * names objects only as it is told. It never lists: Renovo deletes only what
 * it recorded uploading, so it never needs to know what else is there.
 *
 * Every failure is a `DestinationException` naming the step and a reason
 * safe to show an administrator: never a credential, never a signed header.
 */
interface BackupDestination
{
    /** The name of the marker object `test()` writes and removes. */
    public const MARKER = '.renovo-connection-test';

    /**
     * Store a file under a name, and confirm the destination holds exactly
     * it — by its own checksum where it has one, by its size otherwise.
     *
     * @throws DestinationException
     */
    public function put(string $localPath, string $object, string $sha256, int $size): void;

    /**
     * The stored object's size, or null when there is no such object.
     *
     * @throws DestinationException
     */
    public function head(string $object): ?int;

    /**
     * Download an object into a local file.
     *
     * @throws DestinationException
     */
    public function get(string $object, string $localPath): void;

    /**
     * Remove an object. One already gone is not an error.
     *
     * @throws DestinationException
     */
    public function delete(string $object): void;

    /**
     * Write, read back and delete a small marker, so an administrator knows
     * the settings work before a backup depends on them.
     *
     * @throws DestinationException Naming the step that failed.
     */
    public function test(): void;
}
