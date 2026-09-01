<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Lê e grava os DOIS arquivos por trás do botão de alternar modo de tela do
 * SchemaBoard — é o que faz a troca ser de verdade "salvar um arquivo /
 * gerar e abrir outro arquivo", e não só virar uma variável de tela:
 *
 *   erd/diagrama.json          — snapshot do modelo ER ($entities,
 *                                $relations e os contadores de sequência).
 *                                Gravado toda vez que o usuário SAI do modo
 *                                'er' (clica em "Ver schema relacional") e
 *                                lido de volta toda vez que ele VOLTA pro
 *                                modo 'er' (clica em "Ver diagrama ER") —
 *                                é o arquivo sendo "aberto" de verdade, não
 *                                só o estado em memória sendo reaproveitado.
 *
 *   erd/schema-relacional.json — o schema relacional CONVERTIDO (regra em
 *                                RelationalSchemaConverter). Recriado do
 *                                zero toda vez que o usuário entra no modo
 *                                'relational' — por isso "criar e abrir",
 *                                não só "abrir": o arquivo de ontem nunca é
 *                                reaproveitado, ele é sempre gerado de novo
 *                                a partir do diagrama que acabou de ser
 *                                salvo.
 *
 * Ficam no disco 'local' (storage/app/private/erd/...). O editor não tem
 * conceito de usuário/sessão em nenhum outro lugar (nem o "Salvar" no banco,
 * em Diagram, tem) — é um workspace único, então os dois arquivos são
 * compartilhados por quem estiver com o editor aberto.
 */
class ErdFileStore
{
    private const DISCO = 'local';

    private const ARQUIVO_DIAGRAMA = 'erd/diagrama.json';

    private const ARQUIVO_SCHEMA = 'erd/schema-relacional.json';

    /**
     * Salva o snapshot do diagrama ER atual em arquivo.
     *
     * @param  array<int, array>  $entities
     * @param  array<int, array>  $relations
     */
    public static function salvarDiagrama(array $entities, array $relations, int $seq, int $relSeq): void
    {
        Storage::disk(self::DISCO)->put(
            self::ARQUIVO_DIAGRAMA,
            json_encode([
                'entities' => $entities,
                'relations' => $relations,
                'seq' => $seq,
                'relSeq' => $relSeq,
                'salvo_em' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Abre (lê) o arquivo do diagrama ER salvo. Devolve null se o diagrama
     * nunca foi salvo (primeira vez que a tela abre, antes de qualquer troca
     * de modo).
     *
     * @return array{entities: array, relations: array, seq: int, relSeq: int, salvo_em: string}|null
     */
    public static function abrirDiagrama(): ?array
    {
        return self::lerJson(self::ARQUIVO_DIAGRAMA);
    }

    /**
     * Cria (sempre do zero, nunca reaproveita o de antes) o arquivo do
     * schema relacional derivado.
     *
     * @param  array<int, array>  $tables
     * @param  array<int, array>  $links
     */
    public static function criarSchemaRelacional(array $tables, array $links): void
    {
        Storage::disk(self::DISCO)->put(
            self::ARQUIVO_SCHEMA,
            json_encode([
                'tables' => $tables,
                'links' => $links,
                'gerado_em' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Abre (lê) o arquivo do schema relacional. Devolve null se ele ainda
     * não existe (não deveria acontecer em uso normal — `toggleViewMode()`
     * sempre chama `criarSchemaRelacional()` antes de abrir).
     *
     * @return array{tables: array, links: array, gerado_em: string}|null
     */
    public static function abrirSchemaRelacional(): ?array
    {
        return self::lerJson(self::ARQUIVO_SCHEMA);
    }

    /** Caminho (relativo ao disco 'local') de cada arquivo — usado só para exibir na tela de onde os dados vieram. */
    public static function caminhoDiagrama(): string
    {
        return self::ARQUIVO_DIAGRAMA;
    }

    public static function caminhoSchemaRelacional(): string
    {
        return self::ARQUIVO_SCHEMA;
    }

    private static function lerJson(string $caminho): ?array
    {
        if (! Storage::disk(self::DISCO)->exists($caminho)) {
            return null;
        }

        $dados = json_decode(Storage::disk(self::DISCO)->get($caminho), true);

        return is_array($dados) ? $dados : null;
    }
}
