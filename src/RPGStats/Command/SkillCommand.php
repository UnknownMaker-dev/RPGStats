<?php

namespace RPGStats\Command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use RPGStats\RPGStatsPlugin;
use RPGStats\Skill\Skill;

/**
 * /habilidade — lista, detalha, evolui e usa as habilidades.
 *
 * Sem argumento mostra a lista do próprio jogador. `usar` é o caminho que os plugins de
 * item e de menu chamam por trás, então a validação toda mora no SkillManager e não aqui.
 */
class SkillCommand extends Command
{
    public function __construct(private RPGStatsPlugin $plugin)
    {
        parent::__construct(
            "habilidade",
            "Lista, evolui e usa suas habilidades",
            "/habilidade [lista|info <id>|upar <id>|usar <id>]",
            ["habilidades", "skill", "skills"],
        );

        $this->setPermission("rpgstats.habilidade");
    }

    public function execute(CommandSender $sender, string $label, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return false;
        }

        if (!$sender instanceof Player) {
            $sender->sendMessage("§cSó um jogador tem habilidades.");
            return false;
        }

        $sub = strtolower($args[0] ?? "lista");
        $id = $args[1] ?? "";

        return match ($sub) {
            "lista", "list" => $this->showList($sender),
            "info" => $this->showInfo($sender, $id),
            "upar", "up", "evoluir" => $this->upgrade($sender, $id),
            "usar", "use" => $this->use($sender, $id),
            // Sem subcomando reconhecido, o primeiro argumento é tratado como o id: quem
            // digita "/habilidade dash" quer usar a habilidade, não ler um erro de sintaxe.
            default => $this->use($sender, $sub),
        };
    }

    private function showList(Player $player): bool
    {
        $name = $player->getName();
        $manager = $this->plugin->getDataManager();
        $skills = $this->plugin->getSkillRegistry()->all();

        if ($skills === []) {
            $player->sendMessage("§eNenhuma habilidade configurada no habilidades.yml.");
            return true;
        }

        $player->sendMessage("§6─── Suas habilidades ───");
        $player->sendMessage("§7Pontos livres: §f" . $manager->getPoints($name));

        foreach ($skills as $skill) {
            $level = $manager->getSkillLevel($name, $skill->getId());

            if ($level <= 0) {
                $cost = $skill->getUpgradeCost(1);
                $player->sendMessage(
                    "§8• §7{$skill->name} §8(bloqueada) §7— nível §f{$skill->requiredLevel}§7, §f{$cost}§7 ponto(s)"
                );
                continue;
            }

            $line = "§8• §b{$skill->name} §7nv. §f{$level}§7/§f{$skill->maxLevel}";

            if ($skill->type === Skill::TYPE_CRITICO) {
                $line .= " §8· §7passiva §f" . round($skill->getPower($level), 1) . "%";
            } else {
                $line .= " §8· §b{$skill->getManaCost($level)} mana";

                $remaining = $this->plugin->getSkillManager()->getRemainingTicks($name, $skill->getId());
                if ($remaining > 0) {
                    $line .= sprintf(" §8· §crecarga %.1fs", $remaining / 20);
                }
            }

            $player->sendMessage($line);
        }

        $player->sendMessage("§7Use §f/habilidade upar <id>§7 ou §f/habilidade usar <id>§7.");

        return true;
    }

    private function showInfo(Player $player, string $id): bool
    {
        $skill = $this->plugin->getSkillRegistry()->get($id);

        if ($skill === null) {
            $player->sendMessage("§cEssa habilidade não existe. Veja /habilidade lista.");
            return false;
        }

        $name = $player->getName();
        $level = $this->plugin->getDataManager()->getSkillLevel($name, $skill->getId());
        $shown = max(1, $level);

        $player->sendMessage("§6─── {$skill->name} ───");

        if ($skill->description !== "") {
            $player->sendMessage("§7{$skill->description}");
        }

        $player->sendMessage("§7Tipo: §f{$skill->type}");
        $player->sendMessage("§7Seu nível: §f{$level}§7/§f{$skill->maxLevel}");
        $player->sendMessage("§7Potência no nível {$shown}: §f" . round($skill->getPower($shown), 2));

        if ($skill->type !== Skill::TYPE_CRITICO) {
            $player->sendMessage("§7Custo: §b{$skill->getManaCost($shown)} mana");
            $player->sendMessage("§7Recarga: §f" . round($skill->getCooldownSeconds($shown), 1) . "s");
        }

        if ($level < $skill->maxLevel) {
            $next = $level + 1;
            $player->sendMessage(
                "§7Subir para o nível §f{$next}§7 custa §e{$skill->getUpgradeCost($next)}§7 ponto(s)"
                . " §8(potência " . round($skill->getPower($next), 2) . ")"
            );
        }

        return true;
    }

    private function upgrade(Player $player, string $id): bool
    {
        if ($id === "") {
            $player->sendMessage("§cUso: /habilidade upar <id>");
            return false;
        }

        $error = $this->plugin->getSkillManager()->upgrade($player, $id);

        if ($error !== null) {
            $player->sendMessage($error);
            return false;
        }

        $skill = $this->plugin->getSkillRegistry()->get($id);
        $level = $this->plugin->getDataManager()->getSkillLevel($player->getName(), $id);

        $player->sendMessage("§a{$skill->name} subiu para o nível §f{$level}§a!");

        return true;
    }

    private function use(Player $player, string $id): bool
    {
        if ($id === "") {
            $player->sendMessage("§cUso: /habilidade usar <id>");
            return false;
        }

        $error = $this->plugin->getSkillManager()->use($player, $id);

        if ($error !== null) {
            $player->sendMessage($error);
            return false;
        }

        return true;
    }
}
