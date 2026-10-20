# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## O projeto

**RPGStats** — plugin de PocketMine-MP 5 (API 5.0.0) que adiciona uma ficha de RPG:
seis status diretos, classes, ranks, títulos, modos e habilidades upáveis, tudo carregado
de YAML.

**É um plugin genérico, feito para rodar em qualquer servidor.** Nada de tema entra no
código: as classes, ranks e títulos que existem são só o conteúdo dos YAMLs, e o
vocabulário das mensagens sai do `config.yml`. Os arquivos que vêm na caixa trazem um
exemplo pronto (classes de RPG, ranks E→S), mas o plugin não conhece nenhum deles por nome.

Código e mensagens em **português**.

Três detalhes que confundem quem chega:

- **A versão 2.0 trocou o modelo de atributos**: os seis status diretos substituíram o
  modelo antigo (str/con/dex/wil/mnd/chakra). As seções de arquitetura mais abaixo ainda
  citam o modelo antigo em alguns pontos; o que vale é a tabela "Os seis status".
- **Persistência é YAML puro**, por decisão do servidor: nada de SQLite ou MySQL. O
  `players.yml` é lido para um cache e gravado em lote.
- **O atributo `mana` é o TAMANHO do poço; `mp` é quanto resta dele.** Confundir os dois
  é o erro mais fácil de cometer neste código.

### Os seis status

Aqui o atributo **é** o status: não existe um atributo intermediário como CON que vira
vida. Comprar HP sobe a vida direto — é o que faz a janela de status bater exatamente com
o que o jogador comprou, sem uma tabela de conversão no meio.

| Status | Chave | Vira o quê | Multiplicador |
|---|---|---|---|
| Vida | `hp` | `vida.base + (HP-1) * vida.por-ponto` | `health` |
| Dano | `damage` | `(DANO-1) * dano.por-ponto`, **somado** ao golpe | `damage` |
| Defesa | `defense` | `(DEF-1) * defesa-por-ponto` → mitigação % | `defense` |
| Velocidade | `speed` | `velocidade.base + (VEL-1) * por-ponto`, teto 0.4 | `speed` |
| Mana | `mana` | `mana.base + (MANA-1) * mana.por-ponto` | `mana_max` |
| Vel. ataque | `atk_speed` | fração cortada da recarga, teto 0.6 | `atk_speed` |

A defesa **mitiga por porcentagem**, não subtrai:
`redução = 1 - 100 / (100 + defesa * escala-defesa)`, com teto em `reducao-maxima`. O
retorno é decrescente de propósito — empilhar defesa nunca zera o dano.

### Classe, rank e título

Os três são a mesma mecânica (`Modifier` + `ModifierRegistry`), instanciada três vezes com
arquivos diferentes. A diferença é a cardinalidade: **classe** e **rank** são um por
jogador; **títulos** são acumuláveis e todos valem ao mesmo tempo.

O título tem uma segunda camada: a chave `title` guarda qual deles aparece no nametag.
Ter ≠ exibir. `normalize()` derruba o título exibido se o jogador não o tiver mais.

O registry lê as chaves em **português** (`nome`, `descricao`, `atributos`,
`multiplicadores`), com as inglesas como alternativa para arquivos no formato antigo.
Multiplicadores de fontes diferentes **compõem por multiplicação**; bônus de atributo somam.

Ao adicionar um multiplicador novo, registre a chave em `Modifier::MULTIPLIERS`, senão o
registry ignora e loga aviso.

### Modos

Transformação que o jogador liga e que **cobra mana por ciclo** (`ModeTickTask`). Sem mana,
desliga sozinho. `grupo` dá exclusividade: dois modos do mesmo grupo não convivem.

**Nada de modo vai para o disco.** É estado de sessão: quem sai transformado volta normal.
Persistir traria bônus vivos para jogador offline e relogin com modo ligado sem mana.

O `ModeManager` **implementa `StatProvider` e se registra no `PlayerDataManager`** — é assim
que o bônus de um modo entra em `getStat()` sem que o cálculo de atributo saiba que modos
existem.

### StatProvider

`RPGStats\Stat\StatProvider` é o gancho para plugins de fora (armadura com status, item
equipado, buff) somarem atributo e multiplicador:

```php
$rpg->getDataManager()->registerProvider("rpgarmors", $meuProvider);
```

**Os dois métodos são chamados com muita frequência** — em todo cálculo de dano, em toda
regeneração, a cada tick de HUD. A implementação tem de devolver um mapa já pronto em
memória. Varrer inventário dentro deles derruba o TPS. Desregistre no `onDisable`, senão um
reload deixa o provider morto na lista.

### Habilidades upáveis

`dash`, `teleport` e `critico`. Os números que mudam com o nível são **listas indexadas por
nível**, não fórmulas — a curva fica visível no YAML. Lista mais curta que o nível repete o
último valor, então `custo-mana: [10]` vale para todos os níveis.

`critico` é **passiva**: não passa por `use()`, é lida no handler de dano. Chamar `use()`
nela devolve erro em vez de gastar mana.

Habilidade é comprada com os **mesmos pontos** de nível que compram atributo. Essa disputa
é a escolha de build.

A recarga é runtime puro: some com o servidor. Guardá-la faria o jogador que caiu junto com
o servidor voltar devendo tempo de uma habilidade que nunca saiu.

## Rodar e testar

Não há suíte de testes. O ciclo é: `php -l` em tudo, depois subir um servidor isolado.

```bash
# lint (o php do sistema serve para isso)
for f in $(find src -name "*.php" | sort); do php -l "$f"; done
```

O core do PocketMine **não carrega plugin em pasta** — isso é função do DevTools. Para um
smoke test sem tocar no servidor real, monte um servidor descartável:

```bash
SP=/tmp/rpgstats-test                      # ou o scratchpad da sessão
SERVER=/caminho/do/seu/servidor          # um servidor PocketMine-MP 5 com DevTools
PHP=$SERVER/bin/php7/bin/php               # o php do sistema não tem yaml/pmmpthread

mkdir -p "$SP/plugins/RPGStats" "$SP/data/plugin_data/RPGStats"
cp -r plugin.yml src resources "$SP/plugins/RPGStats/"
cp "$SERVER/plugins/DevTools.phar" "$SP/plugins/"
printf 'server-port=19199\nserver-ip=127.0.0.1\nwhite-list=on\nlevel-name=test\nxbox-auth=off\n' > "$SP/data/server.properties"

# comandos são enviados pelo stdin do console; termine sempre com "stop"
( sleep 6; echo "stats <jogador>"; sleep 2; echo "stop"; sleep 6 ) \
  | timeout 150 "$PHP" "$SERVER/PocketMine-MP.phar" \
      --data="$SP/data" --plugins="$SP/plugins" --no-wizard --disable-ansi --no-log-file \
  > "$SP/boot.log" 2>&1

grep -iE "Enabling RPGStats|error|exception|severe" "$SP/boot.log"
grep "Command output" "$SP/boot.log" | sed 's/Command output | //'
```

Para testar migração de dados, copie o `players.yml` real de
`$SERVER/plugin_data/RPGStats/` para o
`plugin_data/RPGStats/` do servidor de teste antes de subir.

Regeneração não dá para exercitar assim — precisa de um cliente conectado.

## Arquitetura

### Fluxo dos dados do jogador

`PlayerLoginEvent` → `PlayerDataManager::load()` puxa do `players.yml` para um cache em
memória → todas as leituras e escritas acontecem no cache, marcando a entrada como
*dirty* → o disco só é tocado em três momentos: `PlayerQuitEvent` (`unload`), `AutoSaveTask`
(a cada 5 min, `flush`) e `onDisable` (`flush`).

Isso é I/O **em lote, não assíncrono** — o `Config` do PocketMine escreve na main thread.
Se um dia precisar de escrita fora da thread principal, o caminho é uma escrita
assíncrona (uma `AsyncTask` com o YAML já serializado, por exemplo).

Três invariantes do manager que é fácil quebrar sem querer:

- **`getStats()` devolve `null` para quem nunca entrou** e não cria a entrada. Isso é o que
  impede um erro de digitação em `/stats` de gerar lixo no `players.yml`. Todo comando deve
  checar `null` antes de agir.
- **`normalize()` é a migração.** Toda chave nova no formato do jogador precisa entrar lá
  com um default, senão os dados antigos quebram no primeiro load.
- **`keyMap`** mapeia nome minúsculo → chave real no YAML, para busca case-insensitive
  preservando a capitalização original do nome no arquivo.

### Raças, famílias e traits

Os três são a mesma mecânica e compartilham o mesmo código: `Modifier` (valor) e
`ModifierRegistry` (carrega um YAML). O plugin instancia o registry três vezes, com
arquivos e rótulos diferentes. A única diferença de comportamento é a cardinalidade —
raça e família são uma por jogador, traits são acumuláveis.

Cada modificador soma bônus em atributos (`attributes:`) e multiplica valores derivados
(`multipliers:` — `health`, `damage`, `defense`, `speed`, `ki_max`, `ki_regen`,
`health_regen`, `xp_gain`).
Multiplicadores de fontes diferentes **compõem por multiplicação**; bônus de atributo somam.

Ao adicionar um multiplicador novo, registre a chave em `Modifier::MULTIPLIERS`, senão o
registry ignora e loga aviso.

`/race` e `/family` são a **mesma classe** (`ModifierCommand`), registrada duas vezes com
registries diferentes. `TraitsCommand` é separado por causa do `add`/`remove`/`clear`.

### Atributos e fórmulas

Base editável: `str`, `con`, `dex`, `wil`, `mnd`, `chakra` (+ os recursos `tp` e `ki`).
`getBaseStat()` é o valor cru; `getStat()` já soma raça + família + traits.

| Derivado | Fórmula | Multiplicador |
|---|---|---|
| Vida máxima | `20 + (CON-1) * 2` | `health` |
| Ki máximo | `20 + (CHAKRA-1) * 10` | `ki_max` |
| Dano bônus | `(STR-1)*0.5 + (WIL-1)*0.3` | `damage` |
| Velocidade | `min(0.1 + (DEX-1)*0.005, 0.345)` | `speed`, travado em 0.4 |
| Defesa | `(MND-1) * combat.defense-per-mnd-point` | `defense` |

O dano bônus é **somado** ao `baseDamage` do evento e o multiplicador incide só sobre o
bônus, não sobre o dano da arma.

A defesa não subtrai dano, **mitiga por porcentagem**:
`redução = 1 - 100 / (100 + defesa * combat.defense-scale)`, com teto em
`combat.max-reduction`. O retorno é decrescente de propósito — empilhar MND nunca zera o
dano. O handler roda em `@priority HIGH`, ou seja **depois** do bônus do atacante (NORMAL),
para a mitigação incidir sobre o golpe inteiro; a armadura do jogo corta o que sobrar.
Dano de vazio e `/kill` passam sem redução.

`refresh()` reaplica vida máxima, velocidade e o teto de Ki, e roda sempre que um atributo,
raça, família ou trait muda. Ki e TP **não** disparam refresh (`refreshIfDerived`) — sem
isso a regeneração reaplicaria atributos a cada 2 segundos, para cada jogador.

`applyAttributes()` só corta vida para baixo, nunca cura: curar a cada alteração seria
explorável com relog.

### XP, níveis e pontos

Matar rende XP para quem deu o golpe final (`EntityDeathEvent` — `PlayerDeathEvent` herda
dele, então o mesmo handler cobre mob e jogador). Quanto vale cada abate está em
`progression.xp-per-kill`, com sobrescrita por entidade em `by-entity`.

XP para subir: `xp.base * nível ^ xp.exponent`. Cada nível paga
`progression.points-per-level` pontos, gastos em atributo por `/points spend`. No nível
máximo o XP para de acumular e nem entra na conta.

Três decisões que não são acidente:

- **`xp`, `level` e `points` estão fora de `EDITABLE`** (ficam em `PROGRESSION`). Se
  entrassem, `/stats set fulano xp 999` furaria o level-up e `/stats set fulano points`
  daria atributo de graça. Quem mexe nisso é `/level` e `/points`.
- **Só `addXp()` faz subir de nível**, porque é o único caminho que entrega os pontos.
  Por isso `/level set` trava o XP um abaixo do necessário para subir.
- **Não existe descer de nível.** `/level remove` só tira XP do nível atual. Devolver
  nível exigiria retomar pontos que o jogador talvez já tenha gasto.

O ganho normal aparece na barra de ação (`sendActionBarMessage`) e não no chat — a cada
abate, uma linha no chat seria spam. O level-up, esse sim, vai para o chat.

### TP e treino

O TP era um número morto no arquivo do jogador até 19/08/2026; hoje é a segunda moeda
de atributo. Ele é ganho **batendo**: todo `EntityDamageByEntityEvent` em que o atacante
é jogador rende `dano final * training.tp-per-damage` de TP.

O handler roda em `@priority MONITOR` de propósito — lá o dano já passou pelo bônus do
atacante, pela defesa do alvo e pela armadura, então o TP acompanha o dano que de fato
saiu, não o que foi anunciado.

**São duas moedas que convivem, com fontes e regras diferentes:**

| | `points` | `tp` |
|---|---|---|
| Vem de | matar → XP → nível | acertar golpe |
| Custo por ponto de atributo | 1, fixo | `cost.base * valor ^ cost.exponent` |
| Comando | `/points spend` | `/training spend` |
| Dentro de `EDITABLE`? | não | sim |

O custo do TP cresce porque do lado de quem ganha **não há teto**: quem farmar golpe o
dia inteiro acumula TP sem limite, e só o preço segura a inflação de atributo. O ponto
de nível não precisa disso — a fonte já é limitada pela curva de XP.

O TP continua em `EDITABLE` (`/stats set fulano tp 100` funciona), igual ao Ki e pelo
mesmo motivo: é recurso, não progressão. O que não pode entrar lá é `points`.

Cada ponto é cobrado pelo preço da própria faixa, então comprar 5 de uma vez custa o
mesmo que comprar 5 vezes 1 — `getTrainingCostFor()` soma faixa a faixa.

### Telas

Duas tecnologias diferentes, de propósito:

- **`/stats`** abre a **ficha desenhada** (`DialogueMenu`), em cima da tela de diálogo de
  NPC, quando `ui.formato` é `desenhada`. É onde o resource pack manda no layout.
- **`/points` e `/training`** (e o `/stats` com `ui.formato: formulario`) abrem
  **formulários** (`StatsMenu`). Com o pack, eles ganham o mesmo visual da ficha; sem o
  pack, ficam com a cara padrão do Bedrock e servem de plano B.

Console e `/stats <jogador>` continuam em texto.

O **item de menu** (`MenuItemManager` + `MenuItemListener`, seção `item-menu:` do config)
abre a mesma ficha que o `/stats`, pelo `RPGStatsPlugin::openSheet()`. Ele é reconhecido
pela tag `rpgstats:menu`, nunca pelo nome. A trava tem três peças: a tag
`minecraft:item_lock` (o cliente nem deixa mover), o cancelamento de toda transação que
toque no item ou no slot dele, e um listener no inventário que devolve o item no tick
seguinte quando algo o tira de fora de uma transação (`/clear`, outro plugin). Na morte
ele fica por `keepOnDeath`.

O visual inteiro (ficha, HUD e formulários) está em `resourcepack/`, e o README de lá
documenta os contratos byte a byte. O resumo do que **não dá para mudar de um lado só**:

#### A ficha desenhada

Formulário do Bedrock tem layout fixo. O diálogo de NPC dá uma tela em branco onde o pack
desenha o que quiser, e os dados vão nos botões:

- **O nome do diálogo é a chave**: `rpgstats|<aba>|t:<tema>|`. O pack só desenha a ficha
  quando acha `rpgstats|`; a aba (`ficha`, `detalhes`, `bonus`) e o tema são tokens
  cercados por `|`, para um nunca casar dentro de outro.
- **A ordem dos botões é a posição na tela.** 0–5 cartões dos atributos, 6 fechar,
  7/8/9 menos, quantidade e mais, 10–12 abas, 13–19 só texto (nome, subtítulo, nível,
  XP em %, XP em texto, pontos, TP). Inserir um botão no meio desloca todos.
- **O texto dos cartões tem campos de largura fixa**: valor (6) + nome (12), completados
  com `@`, e depois custo e efeito livres. A UI só corta texto pelo começo (`'%.Ns'`).
  Os campos fixos são **só ASCII** — o corte conta bytes. É por isso que
  `attributeName()` não tem acento.
- **O texto do diálogo** é a dica do rodapé na aba de atributos e o corpo nas outras.

A tela de diálogo vanilla continua lá: `ui/npc_interact_screen.json` só acrescenta a ficha
e esconde a janela padrão quando a chave aparece. Diálogo de outro NPC não muda.

#### Temas

`Form/UiPreset.php` guarda o tema de cada jogador (chave `ui` do `players.yml`, troca com
`/stats tema <tema>`). O tema é só um token no nome do diálogo: as texturas de destaque
são brancas e o pack pinta cada uma com a cor do tema (`color` da imagem). Tema novo =
painel no `ficha.json` + entrada no `UiPreset`, sem textura nova.

Os estilos antigos (`original`, `jade`, `tinta`, `sangue`, `pergaminho`) viram o tema
equivalente em `UiPreset::resolve()`, que é o que o `normalize()` usa.

#### Formulários

Não há biblioteca de formulário: o PocketMine 5 já traz `pocketmine\form\Form` e
`Player::sendForm()`. `MenuForm` monta o "simple form" e liga cada botão a um `Closure`;
`StatsMenu` tem as telas. Cada compra reabre a mesma tela.

`MenuForm` põe a marca invisível `§r§p§g§r` na frente de todo título. É ela que o
`ui/server_form.json` do pack procura para trocar a cara do formulário — os de outros
plugins continuam padrão. Os textos usam cor só nos números (`§6`), porque precisam ler
bem no fundo escuro do pack e no claro do formulário padrão.

#### Texturas

Geradas por `resourcepack/tools/gerar_texturas.py`; editar um PNG direto some na próxima
execução. O miolo das peças com nine-slice precisa ser liso, e imagem sem nine-slice vai
com `"keep_ratio": false` (o padrão do Bedrock mantém a proporção).

`libs/npcdialogue/` é a lib de diálogo de NPC portada (re-namespaceada). O PocketMine
não tem diálogo de NPC nativo — ela cria um NPC falso e intercepta pacote cru.

### HUD (resource pack)

O canal de dados é o **título**: `HudManager` manda **um título só**, com todos os
valores em campos de largura fixa:

```
rpgh:h085m100x037l12@@a34/40@@@@@@b120/120@@@@
```

`h` vida %, `m` mana %, `x` XP do nível %, `l` nível, `a`/`b` vida e mana em texto. A
letra na frente de cada campo é o que impede `100100` (vida e mana cheias) de sumir na
subtração que separa os campos. Um título por valor se perderia quando vários mudam no
mesmo tick.

`ui/hud_screen.json` esconde o título que começa com `rpgh:`. Daí a regra que não dá para
esquecer: **sem o pack instalado, o jogador lê esse título na tela**. É por isso que
`hud.enabled` nasce `false` e a `HudTask` só é agendada quando a HUD está ligada.

A `HudManager` só reenvia quando algo mudou — o controle da UI só relê o valor quando o
texto do título **muda**. Mudar o formato exige mexer em `HudManager` e em
`ui/rpgstats/hud.json` na mesma leva.

### Regeneração

`RegenTask` roda a cada 40 ticks e recupera Ki e vida. Pula quem está morto, em espectador
ou marcado pelo `CombatTracker` — que registra o tick do último dano de **atacante e alvo**
(via handler `@priority MONITOR` em `EntityDamageEvent`) e segura a regeneração por alguns
segundos. O tracker é estado de runtime puro, nada dele vai para o disco.

## Comandos e permissões

Registrados com o prefixo `rpgstats`; `default: op`, salvo onde indicado.

| Comando | Aliases | Permissão |
|---|---|---|
| `/stats [jogador]` · `tema [tema]` · `<add\|remove\|set> <jogador> <stat> <qtd>` | `attributes` | `rpgstats.stats` (+ `.admin`) |
| `/ki [jogador]` · `<add\|remove\|set> <jogador> <qtd>` | `energia`, `qi` | `rpgstats.ki` |
| `/race [jogador]` · `<list\|info\|set\|remove>` | `raca` | `rpgstats.race` |
| `/family [jogador]` · `<list\|info\|set\|remove>` | `familia`, `cla` | `rpgstats.family` |
| `/traits [jogador]` · `<list\|info\|add\|remove\|clear>` | `trait` | `rpgstats.traits` |
| `/points [jogador]` · `spend <atributo> [qtd]` · `<add\|remove\|set> <jogador> <qtd>` | `pontos` | `rpgstats.points` (+ `.admin`) |
| `/level [jogador]` · `<add\|remove\|set> <jogador> <xp>` | `nivel`, `exp` | `rpgstats.level` (+ `.admin`) |
| `/training [jogador]` · `spend <atributo> [qtd]` · `<add\|remove\|set> <jogador> <qtd>` | `treino`, `treinar` | `rpgstats.training` (+ `.admin`) |

Sem argumento, cada comando mostra os dados de quem executou (só para jogador, não console).

`/stats`, `/points`, `/level` e `/training` são a exceção do `default: op`: a permissão base é `default: true`,
porque gastar os próprios pontos é ação de jogador comum. Alterar os dados de **outro**
jogador exige a permissão `.admin` correspondente, checada dentro do `execute()`.

Dois comandos não têm o nome óbvio porque o PocketMine já registra o nome óbvio: `/level`
(não `/xp`) e `/training` (não `/tp`, que é o teleporte). O nome repetido só resolveria
como `/rpgstats:xp` e `/rpgstats:tp`.

## Arquivos de configuração

`resources/` guarda os defaults, copiados para `plugin_data/RPGStats/` no primeiro enable
via `saveResource()`. **Editar um arquivo em `resources/` não afeta um servidor que já rodou**
— lá o arquivo já existe e não é sobrescrito.

- `config.yml` — raça/família padrão, intervalos e taxas de regeneração, escala da defesa
  (`combat:`), curva de XP e recompensas por abate (`progression:`), TP por dano e custo
  dos atributos (`training:`), HUD (`hud:`), intervalo do auto-save
- `classes.yml`, `ranks.yml`, `titulos.yml` — os modificadores; formato documentado em comentários dentro de `classes.yml`
- `modos.yml` — as transformações; `habilidades.yml` — as habilidades upáveis

## Convenções

- Commits em português, no padrão `feat:` / `fix:` / `refactor:`. **Toda alteração no plugin
  termina em commit**, sem esperar o usuário pedir.
- Rodar `php -l` em todo arquivo alterado antes de commitar.
- Mensagens ao jogador usam códigos `§` de cor, no estilo já presente no código.

## Pendências conhecidas

- A interface nova (ficha, HUD e formulários) foi validada no renderizador offline, mas
  ainda não num cliente Bedrock: falta conferir no jogo o clique dos cartões e das abas,
  a barra de XP e a HUD antes de ligar `ui.formato: desenhada` e `hud.enabled: true`.
- `RPGArmors` e `RPGItems` vão entrar como `StatProvider`; enquanto não entram, a única
  fonte externa de bônus é o `ModeManager`.
