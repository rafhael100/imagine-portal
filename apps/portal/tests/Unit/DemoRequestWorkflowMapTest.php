<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Presentation\DemoRequestWorkflowMap;
use PHPUnit\Framework\TestCase;

final class DemoRequestWorkflowMapTest extends TestCase
{
    public function test_exact_seven_domain_transitions_are_visible_but_not_executable(): void
    {
        self::assertSame([
            ['Rascunho', 'Cliente fictício', 'Enviar exemplo', 'Enviada'],
            ['Enviada', 'Equipe Imagine fictícia', 'Iniciar análise fictícia', 'Em análise'],
            ['Em análise', 'Equipe Imagine fictícia', 'Solicitar informações fictícias', 'Aguardando informações do cliente'],
            ['Em análise', 'Equipe Imagine fictícia', 'Marcar resolvida no exemplo', 'Resolvida'],
            ['Aguardando informações do cliente', 'Cliente fictício', 'Complementar exemplo', 'Em análise'],
            ['Resolvida', 'Equipe Imagine fictícia', 'Encerrar exemplo', 'Encerrada'],
            ['Resolvida', 'Equipe Imagine fictícia', 'Reabrir exemplo', 'Em análise'],
        ], DemoRequestWorkflowMap::rows());
        self::assertFalse(DemoRequestWorkflowMap::canExecute());
    }

    public function test_no_closed_to_open_transition_is_exposed(): void
    {
        $rows=DemoRequestWorkflowMap::rows();
        self::assertCount(7,$rows);
        foreach($rows as $row){
            self::assertCount(4,$row);
            self::assertNotSame('Encerrada',$row[0]);
            self::assertStringNotContainsString('autorizada',$row[2]);
            self::assertStringNotContainsString('cliente real',$row[1]);
        }
        self::assertFalse(DemoRequestWorkflowMap::canExecute());
    }
}
