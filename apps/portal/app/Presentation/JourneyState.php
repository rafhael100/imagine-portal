<?php

declare(strict_types=1);

namespace App\Presentation;

/** Fictional presentation states; no legal, financial or operational meaning. */
enum JourneyState: string
{
    case Example = 'example';
    case Illustrated = 'illustrated';
    case Preparing = 'preparing';
    case Waiting = 'waiting';
    case Next = 'next';

    public function label(): string
    {
        return match ($this) {
            self::Example => 'Exemplo ilustrativo',
            self::Illustrated => 'Etapa ilustrada',
            self::Preparing => 'Em preparação fictícia',
            self::Waiting => 'Pendência fictícia',
            self::Next => 'Próxima etapa ilustrativa',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Example => 'neutral',
            self::Illustrated => 'success',
            self::Preparing, self::Next => 'info',
            self::Waiting => 'warning',
        };
    }
}
