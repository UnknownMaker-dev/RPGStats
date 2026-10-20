# Interface do RPGStats

Resource pack com a interface do plugin: a **ficha** do `/stats`, a **HUD** de vida,
mana e XP e os **formulários** de pontos e treino. O visual é flat e escuro, com cartões
de cantos arredondados e uma cor de destaque que muda por tema.

O plugin funciona sem o pack, só que com as telas padrão do Bedrock. Ficha desenhada e
HUD dependem do pack instalado (veja os avisos em [Instalar](#instalar)).

## O que tem aqui

| Arquivo | O que desenha |
|---|---|
| `ui/rpgstats/ficha.json` | a ficha: cabeçalho, barra de XP, abas, seis cartões de atributo, seletor de quantidade |
| `ui/rpgstats/hud.json` | a faixa acima da barra de itens: nível, vida, mana e XP |
| `ui/rpgstats/formulario.json` | os formulários de lista do RPGStats |
| `ui/npc_interact_screen.json` | encaixa a ficha na tela de diálogo de NPC |
| `ui/hud_screen.json` | encaixa a HUD e esconde o título que carrega os dados |
| `ui/server_form.json` | troca a cara só dos formulários marcados pelo RPGStats |
| `textures/ui/rpgstats/` | as texturas, todas geradas por `tools/gerar_texturas.py` |

Nenhuma tela é substituída: os três arquivos de tela vanilla só **acrescentam**
controles (`modifications`) e escondem a janela padrão quando a do RPGStats está aberta.
Diálogos de outros NPCs e formulários de outros plugins continuam iguais.

## Instalar

```bash
SERVER=/caminho/do/seu/servidor

# 1. empacotar com o manifest na RAIZ do zip (sem pasta por cima)
cd resourcepack && zip -r "$SERVER/resource_packs/RPGStatsUI.zip" . -x README.md -x "tools/*"

# 2. registrar no resource_packs.yml, ACIMA de outros packs que tragam ui/
#    resource_stack:
#    - RPGStatsUI.zip

# 3. ligar no plugin_data/RPGStats/config.yml
#    ui:
#      formato: desenhada
#    hud:
#      enabled: true

# 4. reiniciar o servidor
```

O cliente guarda o pack por UUID **e** versão. Depois de editar qualquer arquivo daqui,
suba o `version` do `manifest.json`, senão o jogador continua com o pack antigo em cache.

**Ligue a ficha desenhada e a HUD só com o pack instalado.** As duas mandam dados em
canais que só o pack sabe ler: sem ele, o jogador vê `rpgh:h082m100...` no título e
uma tela de diálogo com quase 40 botões soltos.

## Temas

A cor de destaque é escolhida por jogador com `/stats tema <tema>`. O padrão do servidor
está em `ui.default-preset`.

| Tema | Cor |
|---|---|
| `aurora` (padrão) | azul |
| `esmeralda` | verde |
| `rubi` | vermelho |
| `ametista` | roxo |
| `ambar` | dourado |

As texturas de destaque são brancas e cada tema as pinta com a propriedade `color` da
imagem. Por isso **um tema novo não precisa de textura nova**: acrescente um painel em
`raiz` no `ficha.json`, com o token e a cor, e a entrada em `src/RPGStats/Form/UiPreset.php`.

## Como as telas recebem os dados

O pack não conversa com o servidor: ele lê textos que já chegam ao cliente. A UI só sabe
cortar texto **pelo começo** (`'%.Ns'`) e **remover** um trecho (`texto - 'trecho'`).

Duas lições do primeiro teste num cliente de verdade (Bedrock 1.26.50):

- **Na ficha, nada é cortado.** `'%.Ns'` sobre o texto de um botão da coleção saía vazio,
  então cada texto da tela vem no seu próprio botão.
- **Na HUD, cada campo é cortado direto do título guardado** (`#preserved_text`). Um campo
  derivado de outro campo derivado saía vazio. Número vira número com `+ 0` (`* 1` num
  texto é formatação, não conversão) e texto vai para o rótulo como `'§z' + campo`.

### Ficha (tela de diálogo de NPC)

- **Nome do diálogo**: `rpgstats|<aba>|t:<tema>|`, com aba `ficha`, `detalhes` ou `bonus`.
- **Botões**, lidos por `collection_index`:

| Índice | Conteúdo |
|---|---|
| 0–5 | cartões (clicáveis; o texto deles não é desenhado) |
| 6 | fechar |
| 7, 8, 9 | menos, quantidade, mais |
| 10, 11, 12 | abas |
| 13–19 | só texto: nome, subtítulo, nível, XP em %, XP em texto, pontos, TP |
| 20–37 | só texto, três por cartão na ordem dos atributos: valor, nome, custo + efeito |

- **Texto do diálogo**: a dica do rodapé na aba de atributos, o corpo nas outras duas.

Os textos dos cartões ficam por cima dos botões, fora deles: rótulo não pega clique, então
o toque continua indo para o cartão.

### HUD (título)

Um título só, de 41 caracteres depois do prefixo, em campos de largura fixa completados
com `@` (só ASCII: o corte conta bytes):

```
rpgh:h085m100x037l12@@a34/40@@@@@@b120/120@@@@
     │   │   │   │    │           └ b + 11: mana em texto
     │   │   │   │    └ a + 11: vida em texto
     │   │   │   └ l + 4: nível
     │   │   └ x + 3: XP do nível em %
     │   └ m + 3: mana em %
     └ h + 3: vida em %
```

A letra na frente de cada campo não é enfeite: sem ela, `100100` (vida e mana cheias)
seria apagado inteiro na subtração que separa os campos. E vai um título só porque cada
controle só guarda o valor quando percebe que o título mudou: vários títulos chegando no
mesmo instante podem se atropelar, e um deles se perderia.

### Formulários

O título dos formulários do RPGStats começa com a marca invisível `§r§p§g§r`
(`MenuForm::MARKER`). O pack só troca a cara de quem tem a marca. Os botões continuam
na coleção padrão `form_buttons`, então o índice que volta para o servidor é o mesmo.

## Texturas

```bash
python3 resourcepack/tools/gerar_texturas.py
```

Nada em `textures/ui/rpgstats/` é desenhado à mão: edite a paleta ou o desenho no script
e rode de novo. O miolo das peças com nine-slice precisa ser **liso**, porque é exatamente
ele que o cliente estica.

Imagens **sem** nine-slice (`solido`, `barra`) vão com `"keep_ratio": false`. O padrão do
Bedrock é manter a proporção, e uma barra de 32x8 esticada para 150x8 viraria um
quadradinho no meio.

## Ajustes comuns

**Posição da HUD.** Em `ui/rpgstats/hud.json`, `painel`: 182x28 ancorado embaixo no
meio, 58 px acima da borda, logo acima da fileira de corações e armadura. Se outro pack
mexe na barra de itens, ajuste o `offset`.

**Esconder os corações do jogo.** Acrescente em `ui/hud_screen.json`:

```json
"heart_renderer": { "visible": false }
```

Deixe os corações ligados até confirmar que a barra funciona no seu servidor.
