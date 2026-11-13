<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\DisposablePg18ServiceCards;

final class DisposablePg18ServiceCardsTest extends TestCase
{
    /** @return list<list<string>> */
    private static function preparation(): array
    {
        return [
            ['Sites Imagine · Onepage','DEMO-PROJECT-ONEPAGE','Rascunho de exemplo','3/3 itens revisados','Sem itens pendentes no exemplo'],
            ['Sites Imagine · Landing Page','DEMO-PROJECT-LANDING','Rascunho de exemplo','1/3 itens revisados','Pendente no exemplo: Conteúdo, Consentimento'],
            ['Performance AI','DEMO-PROJECT-PERFORMANCE','Rascunho de exemplo','2/4 itens revisados','Pendente no exemplo: Criativos, Orçamento'],
        ];
    }

    /** @return list<list<string>> */
    private static function requests(): array
    {
        return [
            ['DEMO-REQ-ONEPAGE','DEMO-PROJECT-ONEPAGE','Rascunho','Revisão fictícia 1'],
            ['DEMO-REQ-LANDING','DEMO-PROJECT-LANDING','Enviada','Revisão fictícia 2'],
            ['DEMO-REQ-PERFORMANCE','DEMO-PROJECT-PERFORMANCE','Em análise','Revisão fictícia 3'],
        ];
    }

    public function test_each_project_is_paired_with_its_own_request_and_readiness(): void
    {
        $cards=DisposablePg18ServiceCards::fromRows(self::preparation(),self::requests());
        self::assertCount(3,$cards);
        self::assertSame('DEMO-REQ-ONEPAGE',$cards[0]['request']);
        self::assertSame('3/3 itens revisados',$cards[0]['progress']);
        self::assertStringContainsString('Aguardar revisão interna',$cards[0]['next_step']);
        self::assertSame('DEMO-REQ-LANDING',$cards[1]['request']);
        self::assertSame('Enviada',$cards[1]['request_status']);
        self::assertStringContainsString('Consentimento',$cards[1]['pending']);
        self::assertStringContainsString('Conferir pendências',$cards[1]['next_step']);
        self::assertSame('Em análise',$cards[2]['request_status']);
        self::assertStringContainsString('Orçamento',$cards[2]['pending']);
    }

    public function test_a_missing_project_request_refuses_partial_cards(): void
    {
        $requests=self::requests();
        array_pop($requests);
        $this->expectException(RuntimeException::class);
        DisposablePg18ServiceCards::fromRows(self::preparation(),$requests);
    }

    public function test_multiple_requests_for_same_project_refuse_ambiguous_card(): void
    {
        $requests=self::requests();
        $requests[2][1]='DEMO-PROJECT-ONEPAGE';
        $this->expectException(RuntimeException::class);
        DisposablePg18ServiceCards::fromRows(self::preparation(),$requests);
    }

    public function test_unknown_workflow_state_refuses_card(): void
    {
        $requests=self::requests();
        $requests[1][2]='publicado';
        $this->expectException(RuntimeException::class);
        DisposablePg18ServiceCards::fromRows(self::preparation(),$requests);
    }

    public function test_forged_revision_refuses_card(): void
    {
        $requests=self::requests();
        $requests[1][3]='Revisão fictícia 0';
        $this->expectException(RuntimeException::class);
        DisposablePg18ServiceCards::fromRows(self::preparation(),$requests);
    }

    public function test_completed_checklist_cannot_show_pending_tasks(): void
    {
        $rows=self::preparation();
        $rows[0][4]='Pendente no exemplo: Domínio';
        $this->expectException(RuntimeException::class);
        DisposablePg18ServiceCards::fromRows($rows,self::requests());
    }

    public function test_unknown_product_refuses_card(): void
    {
        $rows=self::preparation();
        $rows[1][0]='Outro fornecedor';
        $this->expectException(RuntimeException::class);
        DisposablePg18ServiceCards::fromRows($rows,self::requests());
    }
}
