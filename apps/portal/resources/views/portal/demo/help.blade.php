<x-portal.layout title="Central de Ajuda" current="help">
    <x-portal.help-context title="Central de Ajuda · DEMONSTRAÇÃO" />
    <x-portal.help-topic-list :catalog="\App\Presentation\DemoHelpCatalog::examples()" />
    <x-portal.message title="Funcionalidades indisponíveis nesta demonstração" message="Não há formulário, contato real, envio de dados, abertura de chamados ou acesso aos produtos por esta Central de Ajuda." tone="warning" />
</x-portal.layout>
