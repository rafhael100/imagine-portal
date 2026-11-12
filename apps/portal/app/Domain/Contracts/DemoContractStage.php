<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/** No 'signed', 'paid' or 'active' stages without verified legal/financial authority. */
enum DemoContractStage: string
{
    case Draft = 'draft';
    case UnderReview = 'under_review';
    case AwaitingIllustrativeFeedback = 'awaiting_illustrative_feedback';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho ilustrativo',
            self::UnderReview => 'Revisão fictícia',
            self::AwaitingIllustrativeFeedback => 'Aguardando retorno fictício',
        };
    }
}
