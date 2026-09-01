<?php

namespace App\Support;

/**
 * Converte o modelo Entidade-Relacionamento (notação de Chen) do SchemaBoard
 * num schema relacional de verdade — a mesma regra clássica que o ERDPlus
 * aplica ao gerar o "Relational Schema" a partir do ER:
 *
 *   1:1 e 1:N — a FK já mora fisicamente do lado certo. O editor sempre
 *   grava assim: toda vez que uma relação nasce (SchemaBoard::onConnect →
 *   garantirColunaFk), a coluna FK é criada na entidade "from", nunca na
 *   "to". A conversão não precisa mexer em nada — só desenha a linha direta
 *   da FK até a PK, sem losango e sem pé de galinha.
 *
 *   N:M — quando as DUAS pontas do relacionamento são "muitos" (qualquer
 *   combinação de cf-many / cf-one-many / cf-zero-many em childCard E
 *   parentCard), não existe onde colocar uma FK só: nenhuma FK cabe direto
 *   em nenhuma das duas entidades sem duplicar linha. A regra clássica é
 *   criar uma TABELA ASSOCIATIVA nova, com uma coluna PK+FK pra cada
 *   entidade original — a chave primária dela é composta pelas duas FKs. A
 *   coluna que o editor tinha criado em "from" só pra sustentar aquela
 *   conexão sai da entidade original nessa projeção: ela não faz sentido
 *   ali num schema relacional correto (é a associativa que carrega a
 *   ligação agora).
 *
 * É uma função PURA: lê $entities/$relations e devolve uma projeção NOVA
 * (tabelas + links). Nunca muta o array recebido — o SchemaBoard chama isso
 * de novo a cada render do modo 'relational', então qualquer edição no
 * diagrama ER (nova relação, coluna removida, cardinalidade trocada) se
 * reflete automaticamente na próxima conversão.
 */
class RelationalSchemaConverter
{
    /**
     * Cardinalidades que representam "muitos" — o resto (cf-one-one,
     * cf-zero-one) é "um".
     *
     * Inclui `cf-many`/`cf-one` mesmo eles não estando mais na lista de
     * cardinalidades aceitas por `SchemaBoard::CARDINALIDADES` — o seed
     * inicial (`mount()`) ainda grava relações com `childCard: 'cf-many'`
     * direto no array, sem passar pelo validador, então continuam podendo
     * aparecer num diagrama existente.
     */
    private const CARDS_MUITOS = ['cf-many', 'cf-one-many', 'cf-zero-many'];

    /**
     * @param  array<int, array>  $entities  mesmo formato de SchemaBoard::$entities
     * @param  array<int, array>  $relations  mesmo formato de SchemaBoard::$relations
     * @return array{tables: array<int, array>, links: array<int, array>}
     */
    public static function convert(array $entities, array $relations): array
    {
        // Tabelas indexadas por id — começam como cópia das entidades; N:M
        // pode remover uma coluna daqui e sempre acrescenta uma tabela nova.
        // Como PHP copia array por valor, mexer em $tables NUNCA volta pro
        // $entities que o chamador passou.
        $tables = [];
        foreach ($entities as $e) {
            $tables[$e['id']] = $e;
        }

        $links = [];

        foreach ($relations as $r) {
            $de = $tables[$r['from']] ?? null;
            $para = $tables[$r['to']] ?? null;

            // Relação órfã (a entidade de uma das pontas já não existe) —
            // não deveria acontecer (deleteEntity limpa em cascata), mas se
            // acontecer é melhor pular do que quebrar a conversão inteira.
            if (! $de || ! $para) {
                continue;
            }

            if (self::ehMuitos($r['childCard']) && self::ehMuitos($r['parentCard'])) {
                [$tabelaAssociativa, $ligacoes] = self::converterParaAssociativa($r, $de, $para);

                // A coluna FK que vivia em "from" só existia pra sustentar
                // essa relação N:M — não pertence mais à tabela original.
                $tables[$r['from']]['attributes'] = array_values(array_filter(
                    $tables[$r['from']]['attributes'],
                    fn ($a) => $a['id'] !== $r['fromAttr']
                ));

                $tables[$tabelaAssociativa['id']] = $tabelaAssociativa;
                array_push($links, ...$ligacoes);

                continue;
            }

            // 1:1 ou 1:N — a FK já está no lugar certo, só liga a linha.
            $links[] = [
                'id' => $r['id'],
                'source' => $r['from'],
                'sourceAttr' => $r['fromAttr'],
                'target' => $r['to'],
                'targetAttr' => $r['toAttr'],
            ];
        }

        return [
            'tables' => array_values($tables),
            'links' => $links,
        ];
    }

    private static function ehMuitos(string $card): bool
    {
        return in_array($card, self::CARDS_MUITOS, true);
    }

    /**
     * Monta a tabela associativa de uma relação N:M e as duas ligações
     * (associativa → cada entidade original) que substituem a relação.
     *
     * @return array{0: array, 1: array<int, array>}
     */
    private static function converterParaAssociativa(array $r, array $de, array $para): array
    {
        $pkDe = self::colunaPk($de);
        $pkPara = self::colunaPk($para);

        $id = 'assoc_'.$r['id'];

        $colDe = [
            'id' => $id.'.'.$de['name'].'_'.($pkDe['name'] ?? 'id'),
            'name' => $de['name'].'_'.($pkDe['name'] ?? 'id'),
            'type' => $pkDe['type'] ?? 'bigint',
            'key' => 'PK', // metade da chave primária composta
            'fk' => true,  // ...e também é FK — as duas coisas ao mesmo tempo
        ];

        $colPara = [
            'id' => $id.'.'.$para['name'].'_'.($pkPara['name'] ?? 'id'),
            'name' => $para['name'].'_'.($pkPara['name'] ?? 'id'),
            'type' => $pkPara['type'] ?? 'bigint',
            'key' => 'PK',
            'fk' => true,
        ];

        $tabela = [
            'id' => $id,
            'name' => self::nomeAssociativa($r, $de, $para),
            // fica entre as duas entidades originais, um pouco abaixo, pra
            // não nascer em cima de nenhuma das duas.
            'x' => (int) round((($de['x'] ?? 0) + ($para['x'] ?? 0)) / 2),
            'y' => (int) round(max($de['y'] ?? 0, $para['y'] ?? 0) + 260),
            'attributes' => [$colDe, $colPara],
            'isAssociative' => true,
        ];

        $ligacoes = [
            [
                'id' => $r['id'].':a',
                'source' => $id,
                'sourceAttr' => $colDe['id'],
                'target' => $de['id'],
                'targetAttr' => $pkDe['id'] ?? null,
            ],
            [
                'id' => $r['id'].':b',
                'source' => $id,
                'sourceAttr' => $colPara['id'],
                'target' => $para['id'],
                'targetAttr' => $pkPara['id'] ?? null,
            ],
        ];

        return [$tabela, $ligacoes];
    }

    /** Coluna identificadora da entidade (PK, ou UQ como alternativa). */
    private static function colunaPk(array $entity): ?array
    {
        foreach ($entity['attributes'] as $a) {
            if ($a['key'] === 'PK') {
                return $a;
            }
        }

        foreach ($entity['attributes'] as $a) {
            if ($a['key'] === 'UQ') {
                return $a;
            }
        }

        return null;
    }

    /**
     * Nome da tabela associativa: usa o verbo do relacionamento quando ele
     * diz alguma coisa (ex.: "matricula"); cai para "entidadeA_entidadeB"
     * quando o nome ainda é o genérico que `onConnect` dá por padrão.
     */
    private static function nomeAssociativa(array $r, array $de, array $para): string
    {
        $nome = trim($r['name']);

        if ($nome !== '' && $nome !== 'relaciona') {
            return $nome;
        }

        return $de['name'].'_'.$para['name'];
    }
}
