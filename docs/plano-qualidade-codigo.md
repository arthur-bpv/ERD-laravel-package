# Diagnóstico e plano de organização do código

Data: 20/10/2026. Escopo: estado atual da árvore de trabalho, inclusive alterações locais ainda não commitadas. Este documento é um plano; não altera o comportamento da aplicação.

## Diagnóstico reproduzível

Foi executado o PHAR oficial do [Sloppy](https://github.com/Heyosseus/sloppy), baixado para fora do repositório e conferido com o SHA-256 da release, dentro do container PHP. Comando: `php /tmp/sloppy.phar scan --format=json --all`, a partir de `/app`. Nenhuma dependência, baseline ou hook foi instalado no projeto.

O Sloppy analisou os 14 arquivos PHP de `app/` (3.859 linhas) e apontou **10 achados**: 9 altos e 1 médio; score **70/100**. São 3 alertas de classe grande (`SL102`), 6 de método grande (`SL101`) e 1 de lista longa de parâmetros (`SL113`). Não houve alertas de duplicação, código morto ou exceção engolida nessa execução. Isso não prova ausência desses problemas; as regras têm limites e não cobrem Blade, CSS ou JavaScript.

| Prioridade | Evidência | Ação proposta |
| --- | --- | --- |
| 1 | `app/Livewire/SchemaBoard.php`: 2.111 linhas, 78 métodos, 27 públicos. Mistura ações Livewire, construção de nós/arestas, geometria de autorrelações, posicionamento de atributos, persistência e sinalização de desatualização. | Extrair primeiro a projeção visual e o posicionamento de atributos de relação para 1 ou 2 colaboradores coesos. Manter as ações públicas e o estado Livewire na classe. Só depois avaliar a extração de regras de edição que possam receber e devolver dados sem depender do ciclo de vida do componente. |
| 2 | `app/Services/ErToRelationalTransformer.php`: 651 linhas. `mapRelation()` tem 94 linhas e complexidade 32; `copyPrimaryKey()` tem 82 linhas, complexidade 24 e 12 parâmetros. | Separar as regras de mapeamento de relacionamentos da montagem de FK/colunas. Introduzir um objeto de opções para a cópia de chave, com nomes explícitos e valores padrão; preservar formato de saída, warnings, IDs e fingerprints. Evitar uma classe por cardinalidade. |
| 3 | `app/Livewire/RelationalBoard.php`: 661 linhas e 29 métodos. `buildEdges()` concentra 81 linhas de rotas, rótulos, cardinalidades e handles. | Extrair a projeção de arestas relacionais para uma classe focada. Deixar persistência, eventos de UI e ações públicas no componente. Revisar as rotinas de sincronização e gravação após essa separação. |
| 4 | `app/Services/RelationalSqlGenerator.php:10`, `app/Support/BoardLayout.php:16` e `app/Support/ErDiagramImport.php:10`: métodos de 110, 182 e 80 linhas. | Dividir em métodos privados por etapa: validar/renderizar DDL, preparar grafo/distribuir nós/atribuir coordenadas e decodificar/validar/normalizar importação. Criar outra classe somente se surgir uma responsabilidade reutilizável e independente. |

## Pontos fora do alcance do Sloppy

- `resources/js/erd/edge-editor.js` tem 569 linhas e registra tanto o canvas ER quanto a interface de edição de relações. Separar os dois registros em módulos, mantendo os nomes e eventos Alpine/WireFlow. As seções de geometria de autorrelação merecem testes próprios antes de qualquer simplificação.
- `resources/views/livewire/schema-board.blade.php` tem 467 linhas e inclui canvas, nós, editores e guia. Extrair apenas blocos estáveis para componentes Blade com contratos claros; manter perto do canvas os trechos que dependem diretamente do escopo `node` e dos eventos Alpine.
- `README.md` ainda apresenta o projeto como um “Health Check” e menciona `/board` e `/flow-check`, ausentes em `routes/web.php`. Atualizar a explicação das rotas e criar um mapa curto do fluxo ER → relacional → SQL, apontando as classes que implementam cada etapa.

## Ordem de execução e critérios

1. **Fixar contratos observáveis.** Usar os testes existentes de edição ER, conversão, autorrelações, organização, importação e geração SQL. Acrescentar casos somente para comportamento que ainda não esteja coberto, especialmente IDs, warnings, cardinalidades, posição e saída SQL. Registrar um exemplo de JSON de entrada e saída para a conversão.
2. **Reduzir `SchemaBoard` por responsabilidade.** Primeiro projeção e layout; depois revisar operações de edição. Cada mudança deve manter nomes das ações Livewire, payloads WireFlow e formato salvo dos diagramas. Não criar traits apenas para esconder linhas da classe.
3. **Isolar as regras de conversão.** Trabalhar por cenário de relação (1:1, 1:N, N:N, recursiva, fraca, composta); manter a API `transform()` e sua saída determinística. Trocar os 12 argumentos de `copyPrimaryKey()` por opções nomeadas quando os testes cobrirem os cenários.
4. **Simplificar os pontos menores.** Extrair `buildEdges()` do quadro relacional; dividir os métodos extensos de SQL, layout e importação. Depois revisar os dois arquivos grandes do frontend e atualizar o README.
5. **Prevenir regressão.** Rodar `php artisan test --compact` no container `app` e `npm test` no container `vite` por etapa; rodar Sloppy novamente e comparar achados e comportamento. Quando a refatoração estabilizar, decidir se vale adicionar Sloppy como dependência de desenvolvimento e usar `sloppy diff` no CI. Criar baseline somente se houver dívida remanescente aceita conscientemente.

Critério de conclusão: cada classe nova deve ter uma responsabilidade identificável; arquivos não precisam atingir um limite arbitrário de linhas. Não se deve dividir uma função coesa só para satisfazer a métrica. O objetivo é tornar decisões de domínio localizáveis e mudanças futuras mais seguras.

## Estado da verificação inicial

- PHP: **151 testes passaram**, 1.042 assertions, com `docker compose exec -T app php artisan test --compact`.
- JavaScript: **6 testes passaram** com `docker compose exec -T vite npm test`.
- Há alterações locais preexistentes em 18 arquivos. A análise e este plano não as modificaram.

## Execução na branch de teste

Branch: `codex/teste-organizacao-codigo`. O diagnóstico acima permanece como retrato **anterior** à refatoração. A execução seguiu as etapas com mudanças de escopo limitado:

- `SchemaBoard` delega o posicionamento de atributos de relacionamento a `ErRelationshipAttributeLayout` e a projeção de arestas ER a `ErRelationshipEdgeProjector`. As ações públicas, o estado Livewire e os eventos WireFlow continuam no componente.
- `RelationalBoard` delega a projeção de arestas a `RelationalEdgeProjector` e as alterações de colunas a `RelationalColumnEditor`. A edição continua persistindo e sincronizando o canvas no mesmo ponto do fluxo.
- `ErToRelationalTransformer` separa os casos 1:N e 1:1 e usa `ForeignKeyBuilder` para copiar chaves. As opções da cópia têm nomes documentados; os avisos continuam na mesma sequência em que são emitidos.
- Geração SQL, importação JSON e cálculo de layout foram divididos em fases internas nomeadas. O editor JavaScript foi separado entre canvas ER e painel de relacionamento. O README foi atualizado com as rotas e o mapa de código atuais.
- A view `schema-board.blade.php` foi revisada. Os blocos maiores compartilham o escopo Alpine `node` dentro do canvas; sua extração para componentes Blade exigiria ampliar o contrato de dados sem reduzir lógica. O modal JSON e o cabeçalho já usam componentes próprios, então a view foi mantida nesta etapa.

Após as alterações, o Sloppy aponta **2 alertas**, ambos `SL102` para os componentes Livewire: score **94/100**. O tamanho restante corresponde principalmente à quantidade de ações públicas e à integração com o canvas. Uma extração adicional só deve ser feita quando houver uma responsabilidade independente demonstrável; dividir o componente apenas para zerar a métrica aumentaria o custo de navegação.

Verificação: **151 testes PHP**, **6 testes JavaScript** e build do Vite passaram. O PHAR do Sloppy continuou externo ao repositório. Não foi criada baseline nem instalado hook.
