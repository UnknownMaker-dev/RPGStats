<?php

namespace RPGStats\Form;

use Closure;
use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;

/**
 * Menu de botões (o "simple form" do Bedrock).
 *
 * O PocketMine 5 já traz a interface Form e o Player::sendForm, então não precisa de
 * biblioteca: o que falta é só montar o JSON e ligar cada botão a um callback.
 *
 * O título leva a marca invisível MARKER na frente: é por ela que o resource pack
 * (`ui/server_form.json`) reconhece os formulários do RPGStats e troca a cara deles,
 * sem mexer nos formulários de outros plugins. Sem o pack, a marca não aparece — são
 * só códigos de cor seguidos.
 */
class MenuForm implements Form
{
    /** Marca do pack. Os dois lados mudam juntos (`resourcepack/ui/server_form.json`). */
    public const MARKER = "§r§p§g§r";

    /** @var array<int, array{text: string}> */
    private array $buttons = [];

    /** @var array<int, Closure(Player): void> */
    private array $handlers = [];

    public function __construct(
        private string $title,
        private string $content = "",
    ) {}

    /** @param (Closure(Player): void)|null $handler */
    public function button(string $text, ?Closure $handler = null): self
    {
        $index = count($this->buttons);

        $this->buttons[$index] = ["text" => $text];

        if ($handler !== null) {
            $this->handlers[$index] = $handler;
        }

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            "type" => "form",
            "title" => self::MARKER . $this->title,
            "content" => $this->content,
            "buttons" => array_values($this->buttons),
        ];
    }

    public function handleResponse(Player $player, $data): void
    {
        // null = o jogador fechou a tela sem escolher nada.
        if ($data === null) {
            return;
        }

        if (!is_int($data)) {
            throw new FormValidationException("Esperava o índice do botão, veio " . gettype($data));
        }

        if (!isset($this->buttons[$data])) {
            throw new FormValidationException("Botão inexistente: {$data}");
        }

        ($this->handlers[$data] ?? null)?->__invoke($player);
    }
}
