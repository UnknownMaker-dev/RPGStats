<?php

namespace RPGStats\Manager;

use pocketmine\player\Player;
use RPGStats\RPGStatsPlugin;

/**
 * Alimenta a HUD do resource pack (`resourcepack/ui/rpgstats/hud.json`).
 *
 * O canal é o título: o pack não lê nada do servidor, ele lê a string do título que
 * chega ao cliente. Vai **um título só**, com todos os valores em campos de largura
 * fixa, porque a UI só sabe cortar texto pelo começo (`'%.Ns'`):
 *
 *     rpgh:h082m100x037l12@@a34/40@@@@@@b120/120@@@@
 *
 * `h` vida %, `m` mana %, `x` XP do nível %, `l` nível, `a` vida em texto, `b` mana
 * em texto. A letra na frente de cada campo é o que impede um valor de aparecer de
 * novo mais adiante e ser apagado junto na subtração. Um título por valor perderia
 * dados quando vários mudam no mesmo tick: o cliente só lê o último.
 *
 * O pack esconde o título que começa com "rpgh:". Consequência prática: **sem o pack
 * instalado, esse título aparece escrito**. Por isso hud.enabled nasce false.
 */
class HudManager
{
    public const PREFIX = "rpgh:";

    private bool $enabled;
    private int $titleStayTicks;

    /** @var array<string, string> nome minúsculo => último título enviado */
    private array $lastSent = [];

    public function __construct(
        RPGStatsPlugin $plugin,
        private PlayerDataManager $dataManager,
    ) {
        $config = $plugin->getConfig();

        $this->enabled = (bool) $config->getNested("hud.enabled", false);
        $this->titleStayTicks = max(20, (int) $config->getNested("hud.title-stay-ticks", 72000));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Manda o título só quando algum valor mudou.
     *
     * O filtro não é só economia de pacote: o controle da UI só relê o valor quando o
     * texto do título **muda**, então reenviar o mesmo não faria nada.
     */
    public function update(Player $player): void
    {
        if (!$this->enabled) {
            return;
        }

        $name = $player->getName();
        $manager = $this->dataManager;

        if ($manager->getStats($name) === null) {
            return;
        }

        $level = $manager->getLevel($name);

        if ($manager->isMaxLevel($name)) {
            $xp = 100;
        } else {
            $needed = $manager->getXpForLevel($level);
            $xp = $needed > 0 ? intdiv($manager->getXp($name) * 100, $needed) : 0;
        }

        $health = (int) ceil($player->getHealth());
        $maxHealth = $player->getMaxHealth();
        $mana = $manager->getMana($name);
        $maxMana = $manager->getMaxMana($name);

        $title = self::PREFIX
            . "h" . self::percent($health, $maxHealth)
            . "m" . self::percent($mana, $maxMana)
            . "x" . self::digits($xp)
            . "l" . self::field((string) $level, 4)
            . "a" . self::field($health . "/" . $maxHealth, 11)
            . "b" . self::field($mana . "/" . $maxMana, 11);

        $key = strtolower($name);

        if (($this->lastSent[$key] ?? null) === $title) {
            return;
        }

        $this->lastSent[$key] = $title;

        // stay alto de propósito: o título aqui é canal de dados, não mensagem —
        // se ele expirasse, a barra congelaria no último valor.
        $player->sendTitle($title, "", 0, $this->titleStayTicks, 0);
    }

    /** Esquece o que foi enviado, para o jogador receber tudo de novo ao voltar. */
    public function forget(string $name): void
    {
        unset($this->lastSent[strtolower($name)]);
    }

    private static function percent(float $current, float $max): string
    {
        return self::digits($max <= 0 ? 0 : (int) round($current / $max * 100));
    }

    /** Porcentagem em três dígitos (000 a 100), o formato fixo dos campos h, m e x. */
    private static function digits(int $value): string
    {
        return str_pad((string) max(0, min(100, $value)), 3, "0", STR_PAD_LEFT);
    }

    /** Texto de largura fixa, completado com "@". O que passar da largura é cortado. */
    private static function field(string $value, int $width): string
    {
        return str_pad(substr($value, 0, $width), $width, "@");
    }
}
