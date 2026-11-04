# Board de análise ER → relacional

Os exemplos podem ser baixados diretamente pela janela **Importar projeto** da página inicial. Ao importar, cada arquivo cria um projeto independente e pode ser convertido para o modelo relacional.

## Cenários reais

- [`marketplace-er.json`](../../public/examples/marketplace-er.json): acompanha o fluxo cliente → pedido → produto e separa endereços, pagamentos, categorias e fornecedores. Demonstra atributos simples, 1:N, 1:1 e duas relações N:N com atributos próprios, como quantidade, preço praticado e prazo do fornecedor.
- [`clinic-er.json`](../../public/examples/clinic-er.json): organiza paciente → consulta → médico, com sala, especialidades e medicamentos. Demonstra atributos simples, autorrelacionamento de mentoria e relações N:N para qualificações e itens prescritos.

As entidades desses arquivos já possuem coordenadas distribuídas por fluxo de leitura e os atributos dos relacionamentos possuem offsets próprios. Assim, os recursos ficam visíveis no board sem empilhar entidades ou balões.

## Matriz técnica

O arquivo [`er-conversion-cases.json`](../../public/examples/er-conversion-cases.json) pode ser carregado por **Importar** ou pelo botão **Criar projeto de análise completo**. O gerador reproduz o arquivo com `node docs/examples/generate-er-conversion-cases.mjs`.

R01–R32 cobrem todas as 16 combinações ordenadas das pontas `0..1`, `1..1`, `0..N` e `1..N`, cada uma sem atributo (número ímpar) e com atributo `occurred_at` (número par, marcado com `+`). As entidades A e B mostram a cardinalidade ao lado do seu número. Os relacionamentos N:N aparecem com um retângulo em volta do losango, inclusive quando não possuem atributos.

Depois da matriz há exemplos de autorrelacionamentos 1:N, N:N e 1:1 com papéis, referência a UQ, PK composta e dois relacionamentos entre o mesmo par de entidades. Os exemplos públicos não apresentam entidades fracas, atributos compostos/multivalorados ou relações ternárias, pois o editor atual não oferece controles para essas configurações.

Na conversão, 1:1 e 1:N levam o atributo do relacionamento para a tabela que recebe a FK; N:N cria tabela associativa e leva o atributo para ela. A representação conceitual e a tabela lógica têm propósitos distintos: atributos por si só não obrigam uma tabela associativa. Algumas restrições de participação total e especialização exigem regras adicionais fora das FKs e aparecem como avisos do conversor.


Os exemplos públicos foram limitados às operações disponíveis no editor ER. Campos de recursos não editáveis não são incluídos; arquivos legados continuam aceitos pelo conversor. As cópias distribuídas ficam em `packages/laravel-erd/resources/examples/`.
