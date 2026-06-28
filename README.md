# Projeto Livewire — Health Check (Livewire · Alpine · AlpineFlow · WireFlow)

Aplicação Laravel 12 que valida, numa única página, a stack **Livewire 4 + Alpine + AlpineFlow + WireFlow** rodando em **Firebase Studio / Google Cloud Workstations** (atrás de proxy HTTPS).

A rota `/flow-check` exercita os quatro pilares:

1. **Livewire (servidor):** contador que incrementa via `wire:click` (round-trip ao servidor).
2. **Alpine (cliente):** toggle 100% no navegador, sem ir ao servidor.
3. **AlpineFlow (diagrama JS+CSS):** um `<x-flow>` com nodes/edges arrastáveis.
4. **WireFlow (ponte servidor → diagrama):** botão que cria um node pelo servidor.

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

## ⚠️ Ajustes obrigatórios em Cloud Workstations / Firebase Studio

O app roda **atrás de um proxy HTTPS** que expõe cada porta num host `PORTA-…cloudworkstations.dev`. Sem os ajustes abaixo o setup parece quebrado mesmo estando correto — o `wire:click` simplesmente não funciona.

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

## Rodar

```bash
composer run dev   # php artisan serve + queue + pail + vite, tudo junto
# ou, em produção/preview:
npm run build && php artisan serve --host 0.0.0.0 --port "$PORT"
```

Acesse `/flow-check` pelo host do preview (`PORTA-…cloudworkstations.dev`).

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
