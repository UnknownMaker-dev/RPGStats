<?php

namespace RPGStats\Form;

/**
 * Tema da ficha desenhada: a cor de destaque da interface.
 *
 * O tema é só um token no nome do diálogo ("|t:<id>|"). Quem sabe a cor é o resource
 * pack: `ui/rpgstats/ficha.json` tem um painel por tema, e cada um pinta as mesmas
 * texturas brancas com a sua cor. O texto que o plugin manda é neutro, então trocar de
 * tema não muda nada aqui além do token.
 *
 * Para criar um tema: acrescente a entrada aqui e o painel em `ficha.json`, com o mesmo
 * id e a cor em `$acento`.
 */
final class UiPreset
{
    public const DEFAULT_ID = "aurora";

    /** @var array<string, string> id => nome legível */
    private const PRESETS = [
        "aurora" => "§bAurora",
        "esmeralda" => "§aEsmeralda",
        "rubi" => "§cRubi",
        "ametista" => "§dAmetista",
        "ambar" => "§6Âmbar",
    ];

    /**
     * Estilos da ficha antiga, que ainda podem estar no players.yml ou no config.yml.
     *
     * @var array<string, string>
     */
    private const LEGADO = [
        "original" => "aurora",
        "jade" => "esmeralda",
        "tinta" => "ametista",
        "sangue" => "rubi",
        "pergaminho" => "ambar",
    ];

    private function __construct(
        public readonly string $id,
        public readonly string $nome,
    ) {}

    /** Devolve o tema pedido, ou o padrão se o id não existir. */
    public static function get(string $id): self
    {
        $id = self::resolve($id);

        return new self($id, self::PRESETS[$id]);
    }

    /** Id válido para o que veio do arquivo: aceita os estilos antigos e cai no padrão. */
    public static function resolve(string $id): string
    {
        $id = strtolower($id);
        $id = self::LEGADO[$id] ?? $id;

        return isset(self::PRESETS[$id]) ? $id : self::DEFAULT_ID;
    }

    /** Token que o pack procura no nome do diálogo. */
    public function token(): string
    {
        return "|t:{$this->id}|";
    }

    public static function exists(string $id): bool
    {
        return isset(self::PRESETS[strtolower($id)]);
    }

    /** @return string[] */
    public static function ids(): array
    {
        return array_keys(self::PRESETS);
    }

    /** @return array<string, string> id => nome legível */
    public static function names(): array
    {
        return self::PRESETS;
    }
}
