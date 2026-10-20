<?php

namespace RPGStats\Form;

use pocketmine\player\Player;
use RPGStats\Manager\PlayerDataManager;
use RPGStats\RPGStatsPlugin;

/**
 * As telas do jogador em formulário: ficha, distribuição de pontos e treino com TP.
 *
 * Cada compra reabre a mesma tela, para o jogador gastar vários pontos sem
 * precisar digitar comando nenhum.
 *
 * As cores são escolhidas para ler bem nos dois fundos: o escuro do resource pack e
 * o claro do formulário padrão. Por isso texto comum vai sem cor e os números em §6.
 */
class StatsMenu
{
    public function __construct(private RPGStatsPlugin $plugin) {}

    /** Ficha do jogador, com os caminhos para gastar ponto e TP. */
    public function open(Player $player): void
    {
        $name = $player->getName();
        $manager = $this->plugin->getDataManager();

        if ($manager->getStats($name) === null) {
            $player->sendMessage("§cSeus dados ainda não foram carregados.");
            return;
        }

        $points = $manager->getPoints($name);
        $tp = $manager->getTp($name);

        $form = new MenuForm("Ficha de " . $name, $this->describe($player));

        $form->button(
            "Distribuir pontos\n§6" . $points . "§r disponível(is)",
            fn(Player $p) => $this->openPoints($p),
        );
        $form->button(
            "Treinar com TP\n§6" . $tp . "§r de TP",
            fn(Player $p) => $this->openTraining($p),
        );
        $form->button(
            "Classe, rank e títulos",
            fn(Player $p) => $this->openModifiers($p),
        );
        $form->button("Fechar");

        $player->sendForm($form);
    }

    /** Gasta ponto de nível: 1 ponto = 1 de atributo, sem custo variável. */
    public function openPoints(Player $player): void
    {
        $name = $player->getName();
        $manager = $this->plugin->getDataManager();
        $points = $manager->getPoints($name);

        $content = $points > 0
            ? "Você tem §6{$points}§r ponto(s). Cada um vale +1 de atributo."
            : "Você não tem pontos. Eles vêm de subir de nível.";

        $form = new MenuForm("Distribuir pontos", $content);

        foreach (PlayerDataManager::ATTRIBUTES as $stat) {
            $base = $manager->getBaseStat($name, $stat);

            $form->button(
                self::nome($stat) . "\n" . $base . " → §6" . ($base + 1),
                function (Player $p) use ($stat): void {
                    $this->spendPoint($p, $stat);
                },
            );
        }

        $form->button("Voltar", fn(Player $p) => $this->open($p));

        $player->sendForm($form);
    }

    /** Gasta TP: o preço sobe junto com o atributo, então cada botão mostra o seu. */
    public function openTraining(Player $player): void
    {
        $name = $player->getName();
        $manager = $this->plugin->getDataManager();
        $tp = $manager->getTp($name);

        $form = new MenuForm(
            "Treino",
            "Você tem §6{$tp}§r de TP. TP vem de acertar golpes, e o preço sobe conforme o atributo.",
        );

        foreach (PlayerDataManager::ATTRIBUTES as $stat) {
            $base = $manager->getBaseStat($name, $stat);
            $cost = $manager->getTrainingCost($base);
            $preco = $tp >= $cost ? "§6" . $cost : "§c" . $cost;

            $form->button(
                self::nome($stat) . "  " . $base . " → " . ($base + 1) .
                "\ncusta " . $preco . " §rTP",
                function (Player $p) use ($stat): void {
                    $this->spendTraining($p, $stat);
                },
            );
        }

        $form->button("Voltar", fn(Player $p) => $this->open($p));

        $player->sendForm($form);
    }

    private function openModifiers(Player $player): void
    {
        $name = $player->getName();
        $manager = $this->plugin->getDataManager();

        $playerClass = $manager->getPlayerClass($name);
        $rank = $manager->getRank($name);
        $titles = $manager->getTitles($name);

        $lines = [];
        $lines[] = "§lClasse§r  " . ($playerClass?->name ?? "nenhuma");

        if ($playerClass !== null && $playerClass->description !== "") {
            $lines[] = $playerClass->description;
        }

        $lines[] = "";
        $lines[] = "§lRank§r  " . ($rank?->name ?? "nenhum");

        if ($rank !== null && $rank->description !== "") {
            $lines[] = $rank->description;
        }

        $lines[] = "";

        if (count($titles) > 0) {
            $lines[] = "§lTítulos§r";

            foreach ($titles as $title) {
                $lines[] = "- " . $title->name;

                foreach ($title->describeBonuses() as $bonus) {
                    $lines[] = "  " . $bonus;
                }
            }
        } else {
            $lines[] = "§lTítulos§r  nenhum";
        }

        $form = new MenuForm("Classe, rank e títulos", implode("\n", $lines));
        $form->button("Voltar", fn(Player $p) => $this->open($p));

        $player->sendForm($form);
    }

    private function spendPoint(Player $player, string $stat): void
    {
        $name = $player->getName();
        $manager = $this->plugin->getDataManager();

        if ($manager->spendPoints($name, $stat, 1)) {
            $player->sendMessage(
                "§a+1 §7em §f" . self::nome($stat) .
                " §7(agora §e" . $manager->getBaseStat($name, $stat) . "§7)."
            );
        } else {
            $player->sendMessage("§cVocê não tem pontos para gastar.");
        }

        // Reabre para gastar o próximo sem digitar nada.
        $this->openPoints($player);
    }

    private function spendTraining(Player $player, string $stat): void
    {
        $name = $player->getName();
        $manager = $this->plugin->getDataManager();
        $cost = $manager->getTrainingCost($manager->getBaseStat($name, $stat));

        if ($manager->spendTraining($name, $stat, 1)) {
            $player->sendMessage(
                "§a+1 §7em §f" . self::nome($stat) . " §7por §e{$cost} §7TP " .
                "(restam §e" . $manager->getTp($name) . "§7)."
            );
        } else {
            $player->sendMessage("§cTP insuficiente: custa §f{$cost}§c.");
        }

        $this->openTraining($player);
    }

    /** Resumo que aparece no corpo da ficha. */
    private function describe(Player $player): string
    {
        $name = $player->getName();
        $manager = $this->plugin->getDataManager();

        $lines = [];

        foreach (PlayerDataManager::ATTRIBUTES as $stat) {
            $base = $manager->getBaseStat($name, $stat);
            $effective = $manager->getStat($name, $stat);
            $suffix = $effective !== $base ? " (base {$base})" : "";

            $lines[] = self::nome($stat) . ": §6" . $effective . "§r" . $suffix;
        }

        $level = $manager->getLevel($name);
        $progress = $manager->isMaxLevel($name)
            ? "(máximo)"
            : "(" . $manager->getXp($name) . "/" . $manager->getXpForLevel($level) . " XP)";

        $lines[] = "";
        $lines[] = "Nível §6{$level}§r {$progress}";
        $lines[] = "Vida §6" . (int) $player->getHealth() . "/" . $player->getMaxHealth() .
            "§r · Mana §6" . $manager->getMana($name) . "/" . $manager->getMaxMana($name);
        $lines[] = "Defesa §6" . round($manager->getDamageReduction($name) * 100, 1) . "%" .
            "§r · Dano bônus §6+" . round($manager->getDamageBonus($name), 2);

        return implode("\n", $lines);
    }

    private static function nome(string $stat): string
    {
        return match ($stat) {
            "hp" => "Vida",
            "damage" => "Dano",
            "defense" => "Defesa",
            "speed" => "Velocidade",
            "mana" => "Mana",
            "atk_speed" => "Vel. de ataque",
            default => strtoupper($stat),
        };
    }
}
