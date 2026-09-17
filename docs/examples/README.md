# Board de análise ER → relacional

O arquivo [`public/examples/er-conversion-cases.json`](../../public/examples/er-conversion-cases.json) pode ser carregado por **Importar** ou pelo botão **Criar board de análise completo**. O gerador reproduz o arquivo com `node docs/examples/generate-er-conversion-cases.mjs`.

R01–R32 cobrem todas as 16 combinações ordenadas das pontas `0..1`, `1..1`, `0..N` e `1..N`, cada uma sem atributo (número ímpar) e com atributo `occurred_at` (número par, marcado com `+`). As entidades A e B mostram a cardinalidade ao lado do seu número. Os relacionamentos N:N aparecem com um retângulo em volta do losango, inclusive quando não possuem atributos.

Depois da matriz há exemplos de autorrelacionamento 1:N, N:N e 1:1 com papéis diferentes; referência a UQ; PK composta; entidade fraca; atributo multivalorado e composto; relacionamento ternário; e dois relacionamentos entre o mesmo par de entidades. O JSON mantém IDs descritivos, posições e tipos para possibilitar inspeção e round trip de exportação/importação.

Na conversão, 1:1 e 1:N levam o atributo do relacionamento para a tabela que recebe a FK; N:N cria tabela associativa e leva o atributo para ela. A representação conceitual e a tabela lógica têm propósitos distintos: atributos por si só não obrigam uma tabela associativa. Algumas restrições de participação total e especialização exigem regras adicionais fora das FKs e aparecem como avisos do conversor.
