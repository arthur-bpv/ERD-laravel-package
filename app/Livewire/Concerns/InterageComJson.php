<?php

namespace App\Livewire\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * O modal de JSON que o quadro ER e o Relacional compartilham.
 *
 * Os dois quadros têm o mesmo componente (`<x-json-modal>`), a mesma chave
 * `showJson` e o mesmo botão `downloadJson` — o que os difere é apenas o
 * conteúdo que entra no preview e o nome do arquivo baixado. Essas duas
 * peças ficam a cargo de quem usa a trait; o resto (abrir/fechar, baixar) é
 * escrito uma vez só aqui.
 *
 * Vive em `Livewire\Concerns` porque só os componentes Livewire a usam, e é o
 * mesmo desenho das traits do pacote de canvas (`WireFlow\Concerns`).
 */
trait InterageComJson
{
    /** Abre/fecha o modal. O Blade do componente só lê esta chave. */
    public bool $showJson = false;

    /**
     * JSON do modelo, já formatado.
     *
     * Implementada pelo quadro, porque cada um tem um formato diferente
     * (entidades/relacionamentos, ou tabelas/chaves estrangeiras).
     */
    abstract public function getJsonPreviewProperty(): string;

    /**
     * Nome do arquivo no download.
     *
     * É um método e não uma propriedade porque o PHP proíbe redeclarar uma
     * propriedade vinda de trait com outro valor padrão — e o valor padrão é
     * justamente o que muda entre os dois quadros.
     */
    abstract protected function jsonFileName(): string;

    /** Alterna a exibição do modal com o JSON do diagrama. */
    public function toggleJson(): void
    {
        $this->showJson = ! $this->showJson;
    }

    /**
     * Baixa o JSON exibido no modal — o mesmo conteúdo de `jsonPreview`.
     */
    public function downloadJson(): StreamedResponse
    {
        $json = $this->jsonPreview;

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            $this->jsonFileName(),
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
