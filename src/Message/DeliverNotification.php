<?php

declare(strict_types=1);
namespace App\Message;
/** Only an opaque identifier is serialized in Messenger, never addresses or secrets. */
final readonly class DeliverNotification { public function __construct(public string $id) {} }
