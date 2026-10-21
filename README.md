# Editor de diagramas ER e modelos relacionais

Aplicação Laravel 12 com Livewire 4, Alpine e WireFlow para desenhar um modelo entidade-relacionamento, convertê-lo em tabelas relacionais e gerar SQL para MySQL, PostgreSQL, Oracle ou SQL Server.

## Executar

O ambiente de desenvolvimento usa dois containers: `app` (PHP 8.3, Laravel e SQLite) e `vite` (assets).

```bash
docker compose up
```

Abra [http://localhost:8000](http://localhost:8000). O primeiro início instala as dependências e executa as migrações. Os arquivos do projeto são montados nos containers; alterações em PHP, Blade, CSS e JavaScript aparecem sem reconstruir a imagem.

Se o Docker estiver em outra máquina, defina `DEV_HOST` no `.env` com o host acessível pelo navegador, sem protocolo ou porta, e recrie o serviço Vite:

```dotenv
DEV_HOST=192.168.0.10
```

```bash
docker compose up -d --force-recreate vite
```

As portas 8000 e 5173 precisam estar acessíveis ao navegador. Consulte `docker compose logs app` e `docker compose logs vite` em caso de erro.

## Páginas

| Rota | Função |
| --- | --- |
| `/` | Painel de projetos: criar, importar e abrir diagramas. |
| `/schema` | Editor ER de demonstração. |
| `/boards/er/{diagram}` | Editor ER de um projeto salvo. |
| `/boards/relational/{diagram}` | Modelo relacional gerado a partir do ER, com edição de tipos e exportação SQL. |
| `/health` e `/up` | Verificações HTTP da aplicação. |

## Importar SQL

Na página inicial, use **Importar projeto SQL**, ao lado da importação JSON. Selecione um arquivo `.sql` de até 2 MB ou cole o script. O banco é identificado por sintaxe específica ou pelo cabeçalho dos SQLs exportados pela aplicação: SQLite, MySQL/MariaDB, PostgreSQL, Oracle ou SQL Server. Se o script usar apenas SQL genérico, escolha o banco no formulário. O banco escolhido é salvo no projeto e aparece no cartão do painel.

A importação cria um ER e um modelo relacional vinculado, preservando tabelas, colunas, tipos e tamanhos, nulabilidade, PKs simples ou compostas, UNIQUE simples e FKs simples ou compostas. Aceita chaves em `CREATE TABLE`, `ALTER TABLE … ADD CONSTRAINT` e índices UNIQUE simples. Referências a tabelas que não estão no arquivo são recusadas. O SQL é analisado como texto e nunca executado.

Este é um importador de estrutura, não um restaurador de backups. Exporte preferencialmente apenas o esquema; blocos de rotinas sem dollar-quote e comandos `DELIMITER` são recusados. Dados, views, rotinas, defaults, checks, geração automática, collations e opções de índices não são representados; o modelo relacional lista avisos sobre as omissões. UNIQUE composto, chaves por expressão, múltiplas referências na mesma coluna e alterações estruturais via `ALTER TABLE` além da adição de chaves são recusados. A cópia relacional guarda as FKs importadas diretamente; regenerá-la posteriormente aplica as regras de conversão ER da aplicação.

## Onde está cada regra

| Etapa | Arquivos principais |
| --- | --- |
| Editar ER | `app/Livewire/SchemaBoard.php`, `resources/views/livewire/schema-board.blade.php`, `resources/js/erd/canvas-controller.js` e `resources/js/erd/relationship-editor.js`. |
| Projetar nós ER | `app/Support/ErEntityNodeProjector.php` monta os nós das entidades; `app/Support/ErSelfRelationshipPortProjector.php` e `resources/js/erd/self-relationship-ports.js` posicionam as portas de autorrelação no servidor e durante o arrasto. |
| Editar atributos de relação | `app/Services/ErRelationshipAttributeEditor.php` aplica inclusão, renomeação e remoção; `SchemaBoard` mantém os eventos e a sincronização visual. |
| Posicionar elementos | `app/Support/BoardLayout.php` e `app/Support/ErRelationshipAttributePositioner.php`. |
| Converter ER para relacional | `app/Services/ErToRelationalTransformer.php` e `app/Services/ReferencedKeyCopier.php`. |
| Editar e desenhar o modelo relacional | `app/Livewire/RelationalBoard.php`, `app/Services/RelationalColumnUpdater.php`, `app/Support/RelationalTableNodeProjector.php` e `app/Support/RelationalForeignKeyEdgeProjector.php`. |
| Detectar mudanças no ER e gerar SQL | `app/Services/RelationalDiagramDriftAnalyzer.php` e `app/Services/RelationalSqlGenerator.php`. |
| Importar JSON | `app/Support/ErDiagramImporter.php`. |
| Importar SQL e identificar o banco | `app/Support/SqlDiagramImporter.php` e `app/Support/SqlDdlTokenizer.php`. |

O estado do diagrama fica no servidor. As ações Livewire atualizam entidades, relações, tabelas e posições; o WireFlow recebe comandos para atualizar o canvas. O editor ER não usa sincronização bidirecional automática do canvas. A transformação é determinística e grava `sourceFingerprint`; a cópia relacional só é regenerada quando o usuário decide. As regras de cardinalidade e seus limites estão em [docs/conversao-er-relacional.md](docs/conversao-er-relacional.md).

## Verificar mudanças

```bash
docker compose exec -T app php artisan test --compact
docker compose exec -T vite npm test
docker compose exec -T vite npm run build
```

O diagnóstico e as prioridades de organização do código estão em [docs/plano-qualidade-codigo.md](docs/plano-qualidade-codigo.md). O Sloppy pode ser executado como PHAR temporário no container PHP, sem alterar `composer.json` ou instalar hooks.

## Dependências e ambiente

```bash
docker compose exec app composer install
docker compose exec vite npm install
```

O WireFlow já fornece o AlpineFlow em `vendor/getartisanflow/wireflow/dist/`; Alpine vem do Livewire. O SQLite fica em `database/database.sqlite`. Quem optar por rodar sem Docker precisa de PHP 8.3+, Composer e Node 22; use `composer install`, `npm install`, `php artisan migrate` e `composer run dev`.
