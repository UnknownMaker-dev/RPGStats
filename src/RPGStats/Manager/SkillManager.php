<?php

namespace RPGStats\Manager;

use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\EndermanTeleportSound;
use pocketmine\world\sound\ExplodeSound;
use RPGStats\RPGStatsPlugin;
use RPGStats\Skill\Skill;

/**
 * Executa as habilidades e cobra mana e recarga.
 *
 * A recarga é estado de runtime puro: vive num mapa em memória e some quando o servidor
 * cai. Guardá-la no disco seria pior do que não guardar — o jogador que caísse junto com
 * o servidor voltaria devendo tempo de uma habilidade que nunca chegou a sair.
 *
 * `critico` é passiva: não passa por `use()`, é lida no cálculo de dano. Chamar `use()`
 * nela devolve um erro explicando isso, em vez de gastar mana à toa.
 */
class SkillManager
{
    /** @var array<string, array<string, int>> nome minúsculo => id da habilidade => tick em que libera */
    private array $cooldowns = [];

    public function __construct(private RPGStatsPlugin $plugin) {}

    // ───────────────────────────── recarga ─────────────────────────────

    public function getRemainingTicks(string $name, string $skillId): int
    {
        $readyAt = $this->cooldowns[strtolower($name)][strtolower($skillId)] ?? 0;

        return max(0, $readyAt - $this->plugin->getServer()->getTick());
    }

    public function isOnCooldown(string $name, string $skillId): bool
    {
        return $this->getRemainingTicks($name, $skillId) > 0;
    }

    private function startCooldown(string $name, string $skillId, int $ticks): void
    {
        if ($ticks <= 0) {
            return;
        }

        $this->cooldowns[strtolower($name)][strtolower($skillId)] =
            $this->plugin->getServer()->getTick() + $ticks;
    }

    public function forget(string $name): void
    {
        unset($this->cooldowns[strtolower($name)]);
    }

    // ───────────────────────────── uso ─────────────────────────────

    /**
     * Tenta usar uma habilidade. Devolve null se deu certo, ou a mensagem do erro.
     *
     * Mana só é debitada depois de o efeito acontecer: se o teleporte não achar destino,
     * o jogador não paga por um deslocamento que não houve.
     */
    public function use(Player $player, string $skillId): ?string
    {
        $name = $player->getName();
        $skill = $this->plugin->getSkillRegistry()->get($skillId);

        if ($skill === null) {
            return "§cEssa habilidade não existe.";
        }

        if ($skill->type === Skill::TYPE_CRITICO) {
            return "§e{$skill->name} é passiva: ela age sozinha nos seus golpes.";
        }

        $manager = $this->plugin->getDataManager();
        $level = $manager->getSkillLevel($name, $skill->getId());

        if ($level <= 0) {
            return "§cVocê ainda não desbloqueou {$skill->name}.";
        }

        if ($skill->permission !== "" && !$player->hasPermission($skill->permission)) {
            return "§cVocê não tem permissão para usar {$skill->name}.";
        }

        $remaining = $this->getRemainingTicks($name, $skill->getId());
        if ($remaining > 0) {
            return sprintf("§cAguarde §f%.1fs§c para usar %s de novo.", $remaining / 20, $skill->name);
        }

        $cost = $skill->getManaCost($level);
        if (!$manager->hasEnoughMana($name, $cost)) {
            return "§cMana insuficiente: {$skill->name} custa §b{$cost}§c.";
        }

        $done = match ($skill->type) {
            Skill::TYPE_DASH => $this->dash($player, $skill->getPower($level)),
            Skill::TYPE_TELEPORT => $this->teleport($player, (int) round($skill->getPower($level))),
            default => false,
        };

        if (!$done) {
            return "§cNão dá para usar {$skill->name} daqui.";
        }

        $manager->removeMana($name, $cost);
        $this->startCooldown($name, $skill->getId(), $skill->getCooldownTicks($level));

        $player->sendActionBarMessage("§b{$skill->name} §7· §b-{$cost} mana");

        return null;
    }

    /** Impulso na direção em que o jogador olha, com um empurrão para cima. */
    private function dash(Player $player, float $power): bool
    {
        $direction = $player->getDirectionVector();

        // O componente vertical é fixo e pequeno: sem ele o dash no chão é freado pelo
        // atrito antes de sair do lugar; alto demais vira um pulo e não um avanço.
        $motion = new Vector3(
            $direction->x * $power,
            0.42,
            $direction->z * $power,
        );

        $player->setMotion($motion);
        $player->getWorld()->addSound($player->getPosition(), new ExplodeSound(), [$player]);

        return true;
    }

    /**
     * Teleporte curto para a frente, parando antes do primeiro obstáculo.
     *
     * Avança bloco a bloco e testa o par pé/cabeça de cada passo. Ir direto para o ponto
     * final deixaria o jogador atravessar paredes — e cair dentro de uma delas.
     */
    private function teleport(Player $player, int $distance): bool
    {
        if ($distance < 1) {
            return false;
        }

        $world = $player->getWorld();
        $start = $player->getPosition();
        $direction = $player->getDirectionVector();

        // Só o plano horizontal: olhar para o chão não deve enterrar o jogador.
        $step = new Vector3($direction->x, 0, $direction->z);
        if ($step->lengthSquared() < 0.0001) {
            return false;
        }
        $step = $step->normalize();

        $destination = null;

        for ($i = 1; $i <= $distance; $i++) {
            $candidate = $start->addVector($step->multiply((float) $i));

            $x = (int) floor($candidate->x);
            $y = (int) floor($candidate->y);
            $z = (int) floor($candidate->z);

            if ($world->getBlockAt($x, $y, $z)->isSolid() || $world->getBlockAt($x, $y + 1, $z)->isSolid()) {
                break;
            }

            $destination = $candidate;
        }

        if ($destination === null) {
            return false;
        }

        $world->addSound($start, new EndermanTeleportSound());
        $player->teleport($destination);
        $world->addSound($destination, new EndermanTeleportSound());

        return true;
    }

    // ───────────────────────────── passivas ─────────────────────────────

    /**
     * Chance de crítico do jogador, de 0 a 1.
     *
     * Soma a potência de toda habilidade do tipo `critico` que ele tenha — se um dia
     * houver duas, elas se acumulam. O teto de 1.0 evita crítico garantido por acidente
     * de balanceamento.
     */
    public function getCritChance(string $name): float
    {
        $chance = 0.0;
        $manager = $this->plugin->getDataManager();

        foreach ($this->plugin->getSkillRegistry()->all() as $skill) {
            if ($skill->type !== Skill::TYPE_CRITICO) {
                continue;
            }

            $level = $manager->getSkillLevel($name, $skill->getId());

            if ($level > 0) {
                $chance += $skill->getPower($level) / 100;
            }
        }

        return min(1.0, $chance);
    }

    // ───────────────────────────── evolução ─────────────────────────────

    /**
     * Sobe uma habilidade um nível, cobrando pontos.
     *
     * Devolve null se deu certo, ou a mensagem do erro. Usa os mesmos pontos de
     * `/pontos` — habilidade e atributo disputam a mesma bolsa, e essa disputa é a
     * escolha de build do jogador.
     */
    public function upgrade(Player $player, string $skillId): ?string
    {
        $name = $player->getName();
        $skill = $this->plugin->getSkillRegistry()->get($skillId);

        if ($skill === null) {
            return "§cEssa habilidade não existe.";
        }

        $manager = $this->plugin->getDataManager();
        $current = $manager->getSkillLevel($name, $skill->getId());

        if ($current >= $skill->maxLevel) {
            return "§e{$skill->name} já está no nível máximo (§f{$skill->maxLevel}§e).";
        }

        $playerLevel = $manager->getLevel($name);
        if ($playerLevel < $skill->requiredLevel) {
            return "§cVocê precisa ser nível §f{$skill->requiredLevel}§c para isso (você é §f{$playerLevel}§c).";
        }

        $target = $current + 1;
        $cost = $skill->getUpgradeCost($target);

        if ($manager->getPoints($name) < $cost) {
            return "§cVocê precisa de §f{$cost}§c ponto(s) e tem §f" . $manager->getPoints($name) . "§c.";
        }

        if ($cost > 0 && !$manager->removePoints($name, $cost)) {
            return "§cNão foi possível cobrar os pontos.";
        }

        $manager->setSkillLevel($name, $skill->getId(), $target);

        return null;
    }
}
