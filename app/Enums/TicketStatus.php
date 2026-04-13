<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case ReadyForAgent = 'ready_for_agent';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Backlog => 'Backlog',
            self::Todo => 'Todo',
            self::ReadyForAgent => 'Ready for Agent',
            self::InProgress => 'In Progress',
            self::Review => 'Review',
            self::Done => 'Done',
        };
    }
}
