#!/usr/bin/env python3
"""
Gera todas as texturas de UI do pack em textures/ui/rpgstats/.

    python3 resourcepack/tools/gerar_texturas.py

Nada em textures/ui/rpgstats/ é desenhado à mão: edite a paleta ou o desenho aqui e
rode de novo.

── Como o visual funciona ──────────────────────────────────────────────────────

O estilo é flat e escuro: painel ardósia, cartões um tom acima, borda de 1 px e
cantos arredondados. A cor do tema NÃO está nos PNGs: as peças de destaque
(`branco`, `contorno`, `barra`) são brancas e a tela pinta cada uma com a
propriedade `color` da imagem. É assim que um único conjunto de texturas serve
todos os temas — trocar de tema é trocar a cor no JSON, não gerar arquivo novo.

Os ícones dos atributos são a exceção: já saem coloridos, porque a cor de cada
atributo é a mesma em qualquer tema (vida é sempre vermelha).

── Nine-slice ──────────────────────────────────────────────────────────────────

As peças que esticam levam um .json com `nineslice_size` e `base_size`. O cliente
estica só o miolo, então o MIOLO PRECISA SER LISO: borda e cantos ficam nas
faixas fixas, o centro é uma cor só.
"""

import json
import pathlib

from PIL import Image, ImageDraw

RAIZ = pathlib.Path(__file__).resolve().parent.parent
SAIDA = RAIZ / "textures" / "ui" / "rpgstats"

# ── Paleta ────────────────────────────────────────────────────────────────────
PAINEL = (17, 19, 26, 244)
PAINEL_BORDA = (50, 56, 74, 255)
CARTAO = (27, 31, 41, 255)
CARTAO_BORDA = (40, 46, 60, 255)
CARTAO_HOVER = (35, 40, 53, 255)
CARTAO_HOVER_BORDA = (62, 70, 92, 255)
CARTAO_PRESSIONADO = (21, 24, 32, 255)
TRILHO = (9, 11, 15, 235)
BRANCO = (255, 255, 255, 255)


def salvar(img: Image.Image, nome: str, nineslice: int | None = None) -> None:
    SAIDA.mkdir(parents=True, exist_ok=True)
    img.save(SAIDA / f"{nome}.png")
    meta = SAIDA / f"{nome}.json"
    if nineslice is None:
        meta.unlink(missing_ok=True)
        return
    meta.write_text(json.dumps({
        "nineslice_size": [nineslice] * 4,
        "base_size": list(img.size),
    }, indent=4) + "\n")


def arredondado(tamanho: int, raio: int, fundo, borda=None) -> Image.Image:
    """Quadrado de cantos arredondados, com borda de 1 px opcional."""
    img = Image.new("RGBA", (tamanho, tamanho), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.rounded_rectangle((0, 0, tamanho - 1, tamanho - 1), raio, fill=borda or fundo)
    if borda is not None:
        d.rounded_rectangle((1, 1, tamanho - 2, tamanho - 2), max(raio - 1, 0), fill=fundo)
    return img


def contorno(tamanho: int, raio: int) -> Image.Image:
    img = arredondado(tamanho, raio, BRANCO)
    d = ImageDraw.Draw(img)
    d.rounded_rectangle((1, 1, tamanho - 2, tamanho - 2), max(raio - 1, 0), fill=(0, 0, 0, 0))
    return img


def barra() -> Image.Image:
    """Preenchimento das barras: branco com leve degradê vertical (a cor vem do JSON)."""
    img = Image.new("RGBA", (32, 8))
    for y in range(8):
        v = 255 - y * 9
        for x in range(32):
            img.putpixel((x, y), (v, v, v, 255))
    return img


# ── Pixel art ─────────────────────────────────────────────────────────────────
# Cada desenho é uma grade de caracteres; '.' é transparente.

ICONES = {
    # 12x12, coloridos: um por atributo, na ordem de PlayerDataManager::ATTRIBUTES.
    "icone_vida": ({"a": (240, 84, 96), "b": (176, 44, 60), "c": (255, 170, 176)}, [
        "............",
        "..aaa..aaa..",
        ".acaaaaaaaa.",
        ".accaaaaaaa.",
        ".acaaaaaaab.",
        ".aaaaaaaaab.",
        "..aaaaaaab..",
        "...aaaaab...",
        "....aaab....",
        ".....ab.....",
        "............",
        "............",
    ]),
    "icone_dano": ({"a": (255, 150, 72), "b": (190, 92, 36), "c": (255, 222, 180), "d": (120, 84, 60)}, [
        "..........cc",
        ".........cac",
        "........cab.",
        ".......cab..",
        "......cab...",
        ".....cab....",
        "..d.cab.....",
        "...dab......",
        "...dd.......",
        "..d..d......",
        ".d..........",
        "............",
    ]),
    "icone_defesa": ({"a": (120, 168, 240), "b": (64, 104, 180), "c": (200, 222, 255)}, [
        "............",
        ".aaaaaaaaaa.",
        ".acccaaaaab.",
        ".acaaaaaaab.",
        ".acaaaaaaab.",
        ".aaaaaaaaab.",
        "..aaaaaaab..",
        "..aaaaaaab..",
        "...aaaaab...",
        "....aaab....",
        ".....ab.....",
        "............",
    ]),
    "icone_velocidade": ({"a": (72, 220, 192), "b": (32, 150, 132)}, [
        "............",
        "............",
        ".aa...aa....",
        "..aa...aa...",
        "...aa...aa..",
        "....aa...aa.",
        "....bb...bb.",
        "...bb...bb..",
        "..bb...bb...",
        ".bb...bb....",
        "............",
        "............",
    ]),
    "icone_mana": ({"a": (160, 120, 255), "b": (104, 70, 200), "c": (226, 210, 255)}, [
        ".....a......",
        ".....a......",
        "....aaa.....",
        "....aaa.....",
        "...aaaaa....",
        "..acaaaaa...",
        "..acaaaab...",
        "..accaaab...",
        "..aaaaaab...",
        "...aaaab....",
        "....bbb.....",
        "............",
    ]),
    "icone_vel_ataque": ({"a": (255, 210, 80), "b": (200, 150, 30)}, [
        "......aaa...",
        ".....aaa....",
        "....aaa.....",
        "...aaa......",
        "..aaaaaaa...",
        "......aab...",
        ".....aab....",
        "....aab.....",
        "...aab......",
        "..ab........",
        "..b.........",
        "............",
    ]),
}

# Brancos, pintados pelo JSON.
GLIFOS = {
    "fechar": [
        "x......x",
        ".x....x.",
        "..x..x..",
        "...xx...",
        "...xx...",
        "..x..x..",
        ".x....x.",
        "x......x",
    ],
    "mais": [
        "...x...",
        "...x...",
        "...x...",
        "xxxxxxx",
        "...x...",
        "...x...",
        "...x...",
    ],
    "menos": [
        ".......",
        ".......",
        ".......",
        "xxxxxxx",
        ".......",
        ".......",
        ".......",
    ],
    # HUD: 7x7
    "hud_vida": [
        ".xx.xx.",
        "xxxxxxx",
        "xxxxxxx",
        ".xxxxx.",
        "..xxx..",
        "...x...",
        ".......",
    ],
    "hud_mana": [
        "...x...",
        "..xxx..",
        ".xxxxx.",
        ".xxxxx.",
        ".xxxxx.",
        "..xxx..",
        ".......",
    ],
    # moedas da ficha: 7x7
    "moeda_pontos": [
        "...x...",
        "..xxx..",
        ".xxxxx.",
        "xxxxxxx",
        ".xxxxx.",
        "..xxx..",
        "...x...",
    ],
    "moeda_tp": [
        "...x...",
        "...x...",
        "xxxxxxx",
        ".xxxxx.",
        "..xxx..",
        ".xx.xx.",
        "x.....x",
    ],
}


def desenhar(grade: list[str], cores: dict[str, tuple]) -> Image.Image:
    img = Image.new("RGBA", (len(grade[0]), len(grade)), (0, 0, 0, 0))
    for y, linha in enumerate(grade):
        for x, ch in enumerate(linha):
            if ch != ".":
                cor = cores[ch]
                img.putpixel((x, y), cor if len(cor) == 4 else (*cor, 255))
    return img


def main() -> None:
    salvar(Image.new("RGBA", (4, 4), BRANCO), "solido")
    salvar(arredondado(24, 4, PAINEL, PAINEL_BORDA), "painel", 5)
    salvar(arredondado(12, 2, CARTAO, CARTAO_BORDA), "cartao", 3)
    salvar(arredondado(12, 2, CARTAO_HOVER, CARTAO_HOVER_BORDA), "cartao_hover", 3)
    salvar(arredondado(12, 2, CARTAO_PRESSIONADO, CARTAO_BORDA), "cartao_pressionado", 3)
    salvar(arredondado(12, 2, BRANCO), "branco", 3)
    salvar(contorno(12, 2), "contorno", 3)
    salvar(arredondado(6, 2, TRILHO), "trilho", 2)
    salvar(barra(), "barra")

    for nome, (cores, grade) in ICONES.items():
        salvar(desenhar(grade, cores), nome)
    for nome, grade in GLIFOS.items():
        salvar(desenhar(grade, {"x": BRANCO}), nome)

    print(f"texturas geradas em {SAIDA.relative_to(RAIZ)}")


if __name__ == "__main__":
    main()
