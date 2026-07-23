# Projeto Livewire — Health Check (Livewire · Alpine · AlpineFlow · WireFlow)

Aplicação Laravel 12 com a stack **Livewire 4 + Alpine + AlpineFlow + WireFlow**.

A rota `/flow-check` exercita os quatro pilares:

1. **Livewire (servidor):** contador que incrementa via `wire:click` (round-trip ao servidor).
2. **Alpine (cliente):** toggle 100% no navegador, sem ir ao servidor.
3. **AlpineFlow (diagrama JS+CSS):** um `<x-flow>` com nodes/edges arrastáveis.
4. **WireFlow (ponte servidor → diagrama):** botão que cria um node pelo servidor.

---

## Como rodar (Docker) 🐳

Você só precisa do **Docker** instalado. Nada de PHP, Composer ou Node na sua máquina.

```bash
docker compose up
```

Pronto. Abra **http://localhost:8000/schema** no navegador.

Na **primeira vez** demora alguns minutos (baixa o PHP, instala o `vendor/` e o `node_modules/`).
Depois disso sobe em **2 segundos**.

Para parar: `Ctrl+C` no terminal.

### O que sobe

| Container | O que faz | Endereço |
|---|---|---|
| `app` | PHP 8.3 rodando `php artisan serve` (o servidor embutido do Laravel) | http://localhost:8000 |
| `vite` | Compila CSS/JS e atualiza o navegador sozinho quando você salva um arquivo | http://localhost:5173 |

Você **não abre** o 5173 no navegador — quem usa ele é a página do 8000.

O banco é um **arquivo SQLite** (`database/database.sqlite`). Não existe container de banco.

### Páginas

| Rota | O que é |
|---|---|
| `/` | Redireciona para `/schema` |
| `/schema` | Modelador de ER (o que está sendo construído) |
| `/board` | Board do AlpineFlow |
| `/flow-check` | Página de diagnóstico da stack |
| `/health` | Responde `{"status":"ok"}` — serve pra testar se o servidor está de pé |

### Se o Docker roda em OUTRO computador

Se você abre o navegador na sua máquina mas o `docker compose` roda num servidor/VM,
a página carrega **sem estilo nenhum**. Motivo: o CSS e o JS são baixados do Vite, e o
endereço gravado na página é `localhost` — que, no seu navegador, é a *sua* máquina.

Conserto: diga ao projeto o endereço do servidor, no `.env`:

```dotenv
DEV_HOST=192.168.0.10      # IP ou nome do servidor, sem http:// e sem porta
```

```bash
docker compose up -d       # recria o container do Vite com o endereço novo
```

Aí acesse `http://192.168.0.10:8000`. Use **o mesmo endereço** no `DEV_HOST` e na barra
do navegador — se você entra por um IP de VPN, o `DEV_HOST` tem que ser o da VPN.

A porta **5173 também precisa estar acessível** pela rede (é dela que vêm o CSS e o JS).

**Sintoma clássico de `DEV_HOST` errado:** a página até carrega com estilo, mas o board
não funciona — nada arrasta, nenhum botão responde. É que o `app.js` é um *ES module*, e
módulos sempre passam por CORS: se a origem não bate, o navegador **bloqueia o JS**
(Livewire, Alpine e AlpineFlow nem iniciam), enquanto o CSS passa numa boa, porque
`<link rel=stylesheet>` não faz essa checagem. No console do navegador (F12) aparece
`blocked by CORS policy`. Confira o `DEV_HOST` e recarregue com `Ctrl+Shift+R`.

> O `vite.config.js` libera o CORS para **qualquer host na porta 8000**, então o nome que
> você usa no navegador não precisa bater com o `DEV_HOST` para o JS ser aceito. Mesmo
> assim, mantenha os dois iguais: o `DEV_HOST` é o endereço de onde o navegador vai
> *baixar* os assets, e ele precisa ser alcançável a partir da sua máquina.

### Comandos do dia a dia

Rode os comandos **dentro do container**, com `docker compose exec app`:

```bash
docker compose exec app php artisan migrate      # rodar migrations
docker compose exec app php artisan make:model Post
docker compose exec app php artisan test
docker compose exec app composer require pacote/novo
docker compose exec vite npm install alguma-lib
```

Editar código no seu editor funciona normalmente — a pasta do projeto está montada dentro dos containers, então **não precisa reiniciar nada** ao mexer em PHP, Blade, CSS ou JS.

### Quando algo der errado

```bash
docker compose logs app          # ver o que o backend reclamou
docker compose logs vite         # ver o que o Vite reclamou
docker compose down && docker compose up    # desligar e ligar de novo
```

Se quiser **começar do zero** (reinstalar tudo):

```bash
docker compose down
rm -rf vendor node_modules public/hot
docker compose up
```

Se o seu usuário do Linux **não for o UID 1000** (confira com `id -u`), troque o `user: "1000:1000"` no `docker-compose.yml` pelo seu número — senão os arquivos criados pelo container ficam com dono errado.

No **Windows/macOS**, se o hot reload não perceber as edições, descomente a linha `VITE_POLLING: "1"` no `docker-compose.yml`.

### Arquivos do ambiente

```
docker-compose.yml      # descreve os 2 containers
docker/Dockerfile       # imagem do PHP 8.3 + Composer
docker/start-app.sh     # o que o container app faz ao subir
docker/start-vite.sh    # o que o container vite faz ao subir
```

---

## Stack

| Camada | Versão |
|---|---|
| PHP | 8.3+ |
| Laravel | 12 |
| Livewire | 4.3 |
| WireFlow (empacota o AlpineFlow) | `getartisanflow/wireflow` ^0.1.2-alpha |
| Vite / Tailwind | Vite 7 / Tailwind 4 |

> **WireFlow já traz o AlpineFlow embutido** no pacote Composer (`vendor/getartisanflow/wireflow/dist/`). **Não** instale o AlpineFlow nem o Alpine via npm separadamente — o Alpine vem junto do bundle do Livewire. Importá-los de novo causaria conflito de duas instâncias do Alpine.

---

## Instalação do zero

```bash
composer require getartisanflow/wireflow   # service provider auto-descoberto
php artisan wireflow:install               # publica config + assets e injeta imports no app.js/app.css
npm install
npm run build
```

O `wireflow:install` deixa:

- `resources/js/app.js` — importa `AlpineFlow` do caminho `vendor/.../wireflow/dist/...` e registra `Alpine.plugin(AlpineFlow)`.
- `resources/css/app.css` — `@import` do `alpineflow.css` + tema.
- `config/wireflow.php`, `config/livewire.php` e `public/vendor/alpineflow`.

---

## 📜 Histórico — ajustes de Cloud Workstations / Firebase Studio

> O projeto **não usa mais** o Firebase Studio; agora roda em Docker (seção no topo).
> Estes ajustes continuam no código porque são inofensivos em `localhost` (o
> `AppServiceProvider` só força HTTPS quando detecta o proxy). Ficam registrados
> aqui caso alguém volte a abrir o projeto atrás de um proxy HTTPS.

O app rodava **atrás de um proxy HTTPS** que expunha cada porta num host `PORTA-…cloudworkstations.dev`. Sem os ajustes abaixo o setup parecia quebrado mesmo estando correto — o `wire:click` simplesmente não funcionava.

### 1. Confiar no proxy — `bootstrap/app.php`

Sem `trustProxies`, o Laravel enxerga o host interno `127.0.0.1:PORT` e gera o endpoint `/livewire/update` num host que o navegador não alcança (`ERR_CONNECTION_REFUSED`).

```php
$middleware->trustProxies(at: '*', headers:
    Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
    | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
```

### 2. Forçar HTTPS + assets via Vite — `app/Providers/AppServiceProvider.php`

```php
// Livewire JS vem do bundle do Vite (app.js); não deixe o Livewire injetar o dele.
config(['livewire.inject_assets' => false]);

// Sem https, o navegador bloqueia assets como "mixed content".
URL::forceScheme('https'); // quando o host for *.cloudworkstations.dev
```

### 3. **NÃO** fixar `ASSET_URL` numa porta — `.env`

Este foi o ponto que fazia **todo o `wire:click` morrer**. O `app.js` é um **ES module**, e módulos sempre carregam via **CORS**. Se `ASSET_URL` aponta para outra porta/host que não a da página, o navegador **bloqueia o `app.js`** (o proxy nginx não envia `Access-Control-Allow-Origin`) → nem Livewire nem Alpine iniciam.

```dotenv
# Deixe SEM valor — assets carregam na MESMA origem da página (qualquer host do proxy).
# ASSET_URL=
```

> O `.env` é gitignored, então **este ajuste não está versionado**: ao clonar num novo workspace, confira que `ASSET_URL` está vazio.

---

## Rodar sem Docker (opcional)

Se você já tem PHP 8.3, Composer e Node 22 na máquina:

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
composer run dev   # php artisan serve + queue + pail + vite, tudo junto
```

Mas o caminho recomendado para o time é o `docker compose up`.

---

## Criando nodes pelo servidor (WireFlow)

Para que um node criado pelo servidor seja **arrastável**, use os métodos do trait `WithWireFlow` — **não** empurre direto em `$this->nodes[]`. Push direto só sincroniza o *dado* via entangle: o AlpineFlow desenha o node, mas não o registra no store de interação, então ele "nasce sem deixar mexer".

```php
use ArtisanFlow\WireFlow\Concerns\WithWireFlow;

public function addNode(): void
{
    $id = (string) (++$this->seq);

    $this->flowAddNodes([[                       // dispara flow:addNodes → pipeline do AlpineFlow
        'id' => $id,
        'position' => ['x' => 160, 'y' => 200],
        'data' => ['label' => "Node servidor #{$id}"],
    ]]);

    $this->flowConnect('2', $id, edgeId: "e2-{$id}"); // dispara flow:connect
}
```

No Blade, o canvas usa `:sync="true"` (entangle bidirecional) + `wire:ignore` (evita o morph do Livewire destruir o DOM gerenciado pelo AlpineFlow).

---

## Testes

```bash
php artisan test
```

- `HealthTest` — `/health` responde `200` com `status: ok`.
- `FlowHealthCheckTest` — a página carrega, o contador Livewire incrementa, e `addNode` despacha os eventos `flow:addNodes` / `flow:connect`.
