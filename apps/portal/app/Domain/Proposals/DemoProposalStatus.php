<?php

namespace App\Domain\Proposals;

/** No accepted contract, payment, active subscription or provisioning status. */
enum DemoProposalStatus: string
{
    case PresentedDemo = 'presented_demo';
}
