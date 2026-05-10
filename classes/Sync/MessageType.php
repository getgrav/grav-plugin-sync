<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync;

/**
 * The kind of payload a Channel carries. Picked at channel-registration time
 * and never changes for that channel's lifetime.
 *
 *  - Crdt:      append-only log of opaque binary updates (e.g. Yjs).
 *               Replay on subscribe is the full log; ordering is whatever the
 *               CRDT layer enforces above sync.
 *  - Broadcast: arbitrary JSON payloads with optional TTL ring-buffer replay.
 *               Best-effort temporal order. Used for comment lifecycle events
 *               and similar "fire-and-let-late-joiners-catch-up" feeds.
 *  - Awareness: fully ephemeral presence/cursor/typing payloads. No storage,
 *               no replay; subscribers only see what's published while they
 *               are actually listening.
 */
enum MessageType: string
{
    case Crdt = 'crdt';
    case Broadcast = 'broadcast';
    case Awareness = 'awareness';
}
