<?php

declare(strict_types=1);

namespace AudioTranscription;

/** Lifecycle state of an Apify run. `Succeeded`, `Failed`, `Aborted` and `TimedOut` are final. */
enum RunStatus: string
{
    case Ready = 'READY';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Aborting = 'ABORTING';
    case Aborted = 'ABORTED';
    case TimingOut = 'TIMING-OUT';
    case TimedOut = 'TIMED-OUT';

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Aborted, self::TimedOut], true);
    }

    /** Finished without results: failed, aborted or timed out. */
    public function isFailure(): bool
    {
        return $this->isFinished() && $this !== self::Succeeded;
    }
}
