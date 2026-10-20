<?php

namespace RPGStats\Command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use RPGStats\Form\UiPreset;
use RPGStats\Manager\PlayerDataManager;
use RPGStats\RPGStatsPlugin;

class StatsCommand extends Command
{
    private const ADMIN_PERMISSION = "rpgstats.stats.admin";

    private RPGStatsPlugin $plugin;

    public function __construct(RPGStatsPlugin $plugin)
    {
        parent::__construct(
            'stats',
            'Mostra e gerencia a ficha de atributos',
            '/stats [jogador] | /stats tema [tema] | /stats <add|remove|set> <jogador> <stat> <quantidade>',
            ["attributes"]
        );
        $this->setPermission("rpgstats.stats");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, string $label, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return false;
        }

        // /stats → os próprios stats
        if (count($args) === 0) {
            if (!$sender instanceof Player) {
                $sender->sendMessage("§cNo console, informe o jogador: /stats <jogador>");
                return false;
            }

            // Jogador vê a ficha; console continua no texto.
            $this->openSheet($sender);

            return true;
        }

        // /stats tema [tema] → troca a cor da ficha ("preset" continua valendo)
        if (in_array(strtolower($args[0]), ["tema", "preset"], true)) {
            return $this->preset($sender, $args[1] ?? null);
        }

        // /stats <jogador> → listar
        if (count($args) === 1) {
            return $this->showStats($sender, $args[0]);
        }

        // /stats <add|remove|set> <jogador> <stat> <quantidade>
        if (count($args) < 4) {
            $sender->sendMessage("§cUso: /stats <add|remove|set> <jogador> <stat> <quantidade>");
            $sender->sendMessage("§7Stats: §f" . implode(", ", PlayerDataManager::EDITABLE));
            return false;
        }

        $action = strtolower($args[0]);
        $targetName = $args[1];
        $statName = strtolower($args[2]);
        $amount = (int) $args[3];

        if ($amount <= 0) {
            $sender->sendMessage("§cA quantidade deve ser um número positivo.");
            return false;
        }

        if (!$sender->hasPermission(self::ADMIN_PERMISSION)) {
            $sender->sendMessage("§cVocê não pode alterar os stats de outros jogadores.");
            return false;
        }

        $manager = $this->plugin->getDataManager();

        if ($manager->getStats($targetName) === null) {
            $sender->sendMessage("§cJogador §f{$targetName} §cnunca entrou no servidor.");
            return false;
        }

        if (!in_array($statName, PlayerDataManager::EDITABLE, true)) {
            $sender->sendMessage("§cStat inválido! Use: §f" . implode(", ", PlayerDataManager::EDITABLE));
            return false;
        }

        $result = match ($action) {
            "add" => $manager->addStat($targetName, $statName, $amount),
            "remove" => $manager->removeStat($targetName, $statName, $amount),
            "set" => $manager->setStat($targetName, $statName, $amount),
            default => null
        };

        if ($result === null) {
            $sender->sendMessage("§cAção inválida! Use: add, remove ou set");
            return false;
        }

        if (!$result) {
            $sender->sendMessage("§cFalha ao alterar o stat.");
            return true;
        }

        $newValue = $manager->getBaseStat($targetName, $statName);
        $effective = $manager->getStat($targetName, $statName);
        $extra = $effective !== $newValue ? " §7(efetivo: §e{$effective}§7)" : "";

        $sender->sendMessage("§aSucesso! §f{$targetName} §7agora tem §e{$newValue} §7de §f{$statName}{$extra}");

        return true;
    }

    /** /stats tema [tema] — sem argumento, lista o que existe. */
    private function preset(CommandSender $sender, ?string $presetId): bool
    {
        if (!$sender instanceof Player) {
            $sender->sendMessage("§cSó um jogador tem ficha para trocar de tema.");
            return false;
        }

        $name = $sender->getName();
        $manager = $this->plugin->getDataManager();

        if ($manager->getStats($name) === null) {
            $sender->sendMessage("§cSeus dados ainda não foram carregados.");
            return false;
        }

        if ($presetId === null) {
            $atual = $manager->getUiPreset($name);

            $sender->sendMessage("§6=== Temas da ficha ===");

            foreach (UiPreset::names() as $id => $nome) {
                $marca = $id === $atual->id ? " §8(atual)" : "";
                $sender->sendMessage("§7- §f{$id} §7· {$nome}{$marca}");
            }

            $sender->sendMessage("§7Troque com §f/stats tema <tema>§7.");

            return true;
        }

        if (!$manager->setUiPreset($name, $presetId)) {
            $sender->sendMessage("§cTema inválido. Use: §f" . implode(", ", UiPreset::ids()));
            return false;
        }

        $sender->sendMessage("§aTema alterado para " . $manager->getUiPreset($name)->nome . "§a.");

        // Reabre já com a cor nova.
        $this->openSheet($sender);

        return true;
    }

    /**
     * Abre a ficha no formato configurado.
     *
     * A ficha desenhada (`DialogueMenu`) só fica legível com o resource pack do RPGStats
     * instalado — ela é uma tela em branco que o pack pinta, e os dados vão em campos de
     * largura fixa completados com "@". Sem o pack, o jogador vê esses campos crus.
     *
     * Por isso `ui.formato` nasce em "formulario": o formulário padrão do Bedrock é feio,
     * mas funciona em qualquer servidor sem pack. Troque para "desenhada" depois de
     * instalar o pack.
     */
    private function openSheet(Player $player): void
    {
        $formato = strtolower((string) $this->plugin->getConfig()->getNested("ui.formato", "formulario"));

        if ($formato === "desenhada" || $formato === "dialogo") {
            $this->plugin->getDialogueMenu()->open($player);
            return;
        }

        $this->plugin->getStatsMenu()->open($player);
    }

    private function showStats(CommandSender $sender, string $targetName): bool
    {
        $manager = $this->plugin->getDataManager();
        $stats = $manager->getStats($targetName);

        if ($stats === null) {
            $sender->sendMessage("§cJogador §f{$targetName} §cnunca entrou no servidor.");
            return false;
        }

        $sender->sendMessage("§6=== Stats de §f{$targetName} §6===");

        foreach (PlayerDataManager::ATTRIBUTES as $stat) {
            $base = $manager->getBaseStat($targetName, $stat);
            $effective = $manager->getStat($targetName, $stat);
            $suffix = $effective !== $base ? " §7(base §f{$base}§7)" : "";

            $sender->sendMessage("§7" . strtoupper($stat) . ": §f{$effective}{$suffix}");
        }

        $sender->sendMessage("§eTP: §f{$stats['tp']}");
        $sender->sendMessage("§bMana: §f{$manager->getMana($targetName)} §7/ §f{$manager->getMaxMana($targetName)}");

        $level = $manager->getLevel($targetName);
        $progress = $manager->isMaxLevel($targetName)
            ? "§7(máximo)"
            : "§7(XP §f{$manager->getXp($targetName)}§7/§f{$manager->getXpForLevel($level)}§7)";

        $sender->sendMessage("§dNível: §f{$level} {$progress}");
        $sender->sendMessage("§6Pontos livres: §f{$manager->getPoints($targetName)}");

        $playerClass = $manager->getPlayerClass($targetName);
        $rank = $manager->getRank($targetName);
        $titles = $manager->getTitles($targetName);

        $sender->sendMessage("§7Classe: §f" . ($playerClass?->name ?? "§8nenhuma"));
        $sender->sendMessage("§7Rank: §f" . ($rank?->name ?? "§8nenhuma"));

        if (count($titles) > 0) {
            $names = array_map(static fn($title) => $title->name, $titles);
            $sender->sendMessage("§7Títulos: §f" . implode("§7, §f", $names));
        } else {
            $sender->sendMessage("§7Títulos: §8nenhum");
        }

        $reduction = round($manager->getDamageReduction($targetName) * 100, 1);

        $sender->sendMessage(
            "§8Vida máx: " . $manager->getMaxHealth($targetName) .
            " | Dano bônus: +" . round($manager->getDamageBonus($targetName), 2) .
            " | Velocidade: " . round($manager->getMovementSpeed($targetName), 3)
        );
        $sender->sendMessage(
            "§8Defesa: " . round($manager->getDefense($targetName), 1) .
            " (absorve " . $reduction . "% do dano)"
        );

        return true;
    }
}
