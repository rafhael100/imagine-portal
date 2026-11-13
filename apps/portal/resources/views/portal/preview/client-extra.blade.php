@php($titles=['finance'=>'Financeiro','files'=>'Arquivos','account'=>'Minha conta'])
<x-portal.layout :title="$titles[$section]" :current="$section"><x-portal.panel :title="$titles[$section]">
@if($section==='account')<p>Cliente de teste · Empresa Aurora</p><p>Usuário: cliente.teste</p><p>A edição de dados e a alteração de senha pelo Portal ainda não estão disponíveis nesta homologação.</p>
@elseif($section==='finance')<p>Não há cobrança real neste ambiente.</p><p>O fluxo de pagamentos pode ser avaliado na contratação de teste.</p><a class="preview-link" href="/_rehearsal/purchase">Testar contratação</a>
@else<p>Envio e compartilhamento de arquivos ainda não estão integrados nesta versão de avaliação.</p>@endif
</x-portal.panel></x-portal.layout>
