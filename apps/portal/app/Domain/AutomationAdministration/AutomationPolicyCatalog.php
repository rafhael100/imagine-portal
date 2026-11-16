<?php

declare(strict_types=1);
namespace App\Domain\AutomationAdministration;

/** Versioned ownership policy metadata, not customer grants or runtime statistics. */
final class AutomationPolicyCatalog
{
 public static function available(): bool {
  return config('automation_admin.enabled')===true && (
    (app()->environment('testing') && config('automation_admin.mode')==='isolated_ci') ||
    (app()->environment('staging') && config('automation_admin.mode')==='private_staging'
      && \App\Domain\PortalAccess\Security\PortalAdminStagingSecurityPolicy::issues('staging')===[]));
 }
 public static function policies(): array {
  return [
   'crm'=>['title'=>'CRM Imagine','owner'=>'Regras comerciais por empresa','policy'=>'Modelos nativos controlados; configuração pelo responsável autorizado.','limit'=>'Limites operacionais aguardam contrato de plano verificado.'],
   'portal'=>['title'=>'Imagine Portal','owner'=>'Políticas administrativas globais','policy'=>'Não concede acesso a dados de empresas nem substitui permissões dos produtos.','limit'=>'Alterações globais permanecem bloqueadas nesta candidata.'],
   'sites'=>['title'=>'Imagine Sites','owner'=>'Produção, revisão e aceite','policy'=>'Publicação depende de revisão e aceite humano autenticado da versão exata.','limit'=>'Nenhuma publicação automática autorizada.'],
   'n8n'=>['title'=>'n8n central','owner'=>'Coordenação técnica interna','policy'=>'Fluxos técnicos mantidos pela Imagine; nenhum acesso aos bancos dos produtos.','limit'=>'Instalação bloqueada até infraestrutura, licença, identidade e backup homologados.'],
   'gateway'=>['title'=>'Imagine AI Gateway','owner'=>'Autorização de IA paga','policy'=>'Fluxos determinísticos não solicitam IA; outros dependem de consentimento, orçamento e autorização.','limit'=>'APIs pagas e credenciais de provedores permanecem desligadas.'],
  ];
 }
}
