<x-portal.layout title="Gateways de pagamento" current="settings">
    <style>
      .gp-banner{border-left:5px solid #F9941D;background:#fff9f0;border-radius:12px;padding:18px 22px}
      .gp-methods{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}
      .gp-methods article{border:1px solid var(--pi-edge);border-radius:12px;padding:18px;background:#fff}
      .gp-label{display:inline-block;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:700;background:#f1f4f8}
      .gp-active{background:#e5f6ed;color:#15603c}.gp-locked{background:#fff0e3;color:#844208}
      .gp-tariffs{margin:10px 0 0;padding:0;list-style:none}
      .gp-tariffs li{padding:7px 0;border-bottom:1px solid var(--pi-edge)}
      .gp-block{padding:12px 16px;border-radius:10px;background:#f7f9fc;margin-top:14px}
    </style>
    <section class="gp-banner" aria-labelledby="gp-title">
        <h2 id="gp-title">Gerenciador multi-gateway — demonstração isolada</h2>
        <p>Asaas permanece como gateway padrão. C6 Bank é uma integração candidata, bloqueada até contrato e homologação. Esta tela não grava configurações nem emite cobranças.</p>
        <span class="gp-label gp-locked">SEM ATIVAÇÃO FINANCEIRA</span>
    </section>
    <section class="pi-panel" aria-labelledby="gp-selection">
        <h2 id="gp-selection">Estratégia de seleção</h2>
        <p><strong>Modo de referência:</strong> {{ ($selectionMode ?? 'manual') === 'lowest_cost' ? 'Menor custo elegível' : 'Escolha manual' }}</p>
        <p><strong>Gateway preferencial:</strong> {{ ($preferredGateway ?? 'asaas') === 'c6' ? 'C6 Bank (candidato)' : 'Asaas' }}</p>
        <p class="pi-muted">Uma tarifa mais baixa só é considerada quando o PSP está habilitado, aprovado, saudável e apto à modalidade solicitada.</p>
    </section>
    <section class="pi-panel" aria-labelledby="gp-providers">
        <h2 id="gp-providers">Provedores</h2>
        <div class="gp-methods">
            @foreach(($gatewayRows ?? []) as $provider)
              <article>
                  <h2>{{ $provider['label'] ?? 'Gateway não identificado' }}</h2>
                  <span class="gp-label {{ ($provider['enabled'] ?? false) ? 'gp-active' : 'gp-locked' }}">{{ ($provider['enabled'] ?? false) ? 'Elegível apenas no cenário de teste' : 'Bloqueado / não homologado' }}</span>
                  <ul class="gp-tariffs" aria-label="Métodos demonstrativos de {{ $provider['label'] ?? 'gateway' }}">
                      @forelse(($provider['methods'] ?? []) as $method)
                        <li><strong>{{ $method['label'] ?? 'Método' }}</strong> — {{ $method['status'] ?? 'Não disponível' }}</li>
                      @empty
                        <li>Nenhum método financeiro habilitado.</li>
                      @endforelse
                  </ul>
              </article>
            @endforeach
        </div>
    </section>
    <section class="pi-panel" aria-labelledby="gp-safe">
        <h2 id="gp-safe">Regras de proteção</h2>
        <div class="gp-block">Novas cobranças seguem o gateway selecionado; cobranças e assinaturas existentes permanecem no provedor original. Timeout ou resultado incerto exigem conciliação antes de qualquer nova tentativa.</div>
        <p class="pi-muted">Somente demonstração de interface, usando dados fictícios. Configuração real dependerá de autenticação administrativa, revisão financeira, persistência e autorização de implantação.</p>
    </section>
</x-portal.layout>
