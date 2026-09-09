# Conversão ER para relacional

O documento fornecido é uma referência de análise. A preferência solicitada para esta aplicação prevalece sobre sua proposta de fundir entidades em relacionamentos 1:1 obrigatórios.

| Relacionamento | Conversão |
| --- | --- |
| 1:1 obrigatório | Manter duas tabelas. Migrar a PK para uma delas como FK obrigatória e única. Levar os atributos do relacionamento para essa tabela. |
| 1:1 com participação obrigatória em uma entidade | Preferir a FK na entidade que exige um parceiro, com NOT NULL e unicidade. |
| 1:1 opcional nas duas entidades | Escolher uma tabela para receber a FK única e anulável. |
| 1:N | Migrar a PK do lado 1 e os atributos do relacionamento para o lado N. |
| 1:0..N | A ausência de filhos é representada pela ausência de linhas filhas. Isso, isoladamente, não permite NULL na FK. |
| N:M, inclusive 0..N:0..M | Criar tabela associativa com as PKs participantes como FKs e PK composta. Incluir os atributos do relacionamento. As FKs da associativa são obrigatórias. |

Ao transformar um relacionamento que antes era 1:N em N:M, uma FK dedicada que havia sido escolhida para aquela relação no lado N deixa de pertencer à entidade e é removida. As duas referências passam a existir somente na tabela associativa. Uma coluna compartilhada por outros relacionamentos é preservada.

## Reaproveitamento ou criação da coluna FK

Se a ponta que receberá a FK já aponta para um atributo existente no ER, o conversor reutiliza essa coluna e acrescenta a referência. Ele não cria uma segunda coluna com outro padrão de nome. Exemplo: `posts.user_id` continua sendo `user_id`; não nasce também `usersId`.

Uma coluna nova só é criada quando não há uma coluna selecionada e compatível no lado que deve guardar a FK. Nesse caso, o nome parte da chave referenciada; colisões recebem o nome da tabela ou do papel como prefixo. Chaves primárias compostas são copiadas por inteiro.

A referência pode apontar para:

- a PK selecionada (toda a PK é migrada quando ela é composta); ou
- uma coluna UQ explicitamente selecionada, caso a entidade não use aquela relação pela PK.

O tipo da FK acompanha o tipo da chave referenciada. Em 1:1 ela recebe unicidade (`UQ/FK`), em 1:N recebe `FK`, e em uma entidade fraca identificada pelo relacionamento recebe `PK/FK`.

## Nulabilidade e leitura dos marcadores

Os marcadores ficam na ponta da entidade cuja quantidade representam. A FK no lado muitos aceita NULL quando cada registro desse lado pode existir sem um registro do lado um.

Exemplo: um cliente pode ter zero ou uma preferência, enquanto cada preferência exige exatamente um cliente. Preferência recebe cliente_id obrigatório e único. Cliente sem preferência simplesmente não tem linha correspondente em Preferência. Portanto, 1:0..1 não implica obrigatoriamente FK anulável.

Se a FK ficar na entidade cuja associação é opcional, ela deve aceitar NULL. Quando ambas as participações são opcionais, a FK migrada também aceita NULL.

Os atributos próprios do relacionamento ficam na mesma tabela que recebe a FK. Se essa FK puder ser nula, esses atributos também precisam aceitar NULL, pois não existe ocorrência do relacionamento para preencher seus valores.

## Auto-relacionamentos

Os dois papéis são parte do modelo e podem ser renomeados no editor. Eles também definem os nomes das colunas geradas.

| Cardinalidade recursiva | Conversão |
| --- | --- |
| 1:N | Manter uma tabela e criar nela uma FK para a própria tabela. O nome usa o papel do lado 1 (por exemplo, `supervisorEmployeeNo`). A FK é anulável somente quando uma linha do lado N pode existir sem a linha do lado 1. |
| N:N | Criar tabela associativa. Cada papel gera uma FK distinta e ambas formam a PK composta (por exemplo, `followerPersonNo` + `followedPersonNo`). |
| 1:1 | Criar uma tabela própria para preservar os dois papéis e os atributos do relacionamento. A primeira FK é `PK/FK`; a segunda é `UQ/FK`. As duas são obrigatórias dentro de uma ocorrência do relacionamento. |

No 1:1 recursivo, as restrições garantem no máximo um registro por papel. Impedir que a mesma pessoa apareça simultaneamente nos dois papéis de pares diferentes, proibir a relação consigo mesma ou exigir que toda pessoa participe são regras adicionais de banco/aplicação.

## Apresentação

O modelo relacional usa setas simples do ArtisanFlow, sem crow's foot. Cada seta parte da tabela que contém a FK e aponta para a tabela cuja PK/UQ é referenciada. As referências da tabela associativa às entidades são N:1, embora o relacionamento ER de origem seja N:M.

Uma FK recursiva 1:N é mostrada como um laço Bézier saindo e voltando por lados diferentes da mesma tabela. Quando um autorrelacionamento gera uma tabela própria (1:1 ou N:N), ela recebe o nome do relacionamento e suas duas FKs são desenhadas em rotas separadas para não se sobreporem.

## Limites

FK e unicidade não garantem, por si só, participação total na tabela referenciada (por exemplo, todo pai possuir ao menos um filho). Essa obrigação exige validação adicional na implementação do banco ou da aplicação, e o conversor emite um aviso quando isso afeta um auto-relacionamento 1:1 obrigatório.

O conversor produz metadados do modelo lógico; as indicações de PK, FK, unicidade e nulabilidade não executam DDL no banco. Modelos relacionais já salvos precisam ser regenerados pela interface para incorporar mudanças de conversão. A remoção visual de crow's foot vale também para modelos salvos.
