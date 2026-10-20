<?php

namespace RPGStats\Form;

use pocketmine\player\Player;
use RPGStats\libs\npcdialogue\NpcDialogueBuilder;
use RPGStats\libs\npcdialogue\NpcDialogueManager;
use RPGStats\Manager\PlayerDataManager;
use RPGStats\RPGStatsPlugin;

/**
 * A ficha desenhada, em cima da tela de diálogo de NPC.
 *
 * Formulário do Bedrock tem layout fixo. O diálogo de NPC não: o resource pack desenha
 * a tela inteira (`resourcepack/ui/rpgstats/ficha.json`) e lê os dados dos botões.
 *
 * Contrato com o pack — **os dois lados mudam juntos**:
 *
 * - **Nome do diálogo**: `rpgstats|<aba>|t:<tema>|`. O pack só desenha a ficha quando
 *   acha `rpgstats|`, escolhe a aba por `|ficha|`, `|detalhes|` ou `|bonus|` e a cor
 *   pelo token do tema. O `|` em volta impede que um token case dentro de outro.
 * - **Ordem dos botões**: o pack pega cada um por `collection_index`.
 *   0–5 cartões dos atributos · 6 fechar · 7/8/9 menos, quantidade, mais ·
 *   10–12 abas · 13–19 só texto (nome, subtítulo, nível, XP em %, XP em texto,
 *   pontos, TP). Inserir um botão no meio desloca todos os seguintes.
 * - **Texto dos cartões**: VALOR (6) + NOME (12) + resto. Os dois primeiros campos têm
 *   largura fixa, completados com "@", porque a UI só corta texto pelo começo
 *   (`'%.Ns'`). Esses campos são **só ASCII**: o corte conta bytes, e um acento no
 *   meio partiria o caractere. O resto (custo e efeito) é livre e pode ter `§`.
 * - **Texto do diálogo**: a dica do rodapé na aba de atributos, o corpo nas outras.
 */
class DialogueMenu
{
    /** Quantidades que o seletor alterna. */
    private const AMOUNTS = [1, 5, 10, 50];

    private const ABA_FICHA = "ficha";
    private const ABA_DETALHES = "detalhes";
    private const ABA_BONUS = "bonus";

    private const LARGURA_VALOR = 6;
    private const LARGURA_NOME = 12;

    /** @var array<string, int> nome minúsculo => índice em AMOUNTS */
    private array $choice = [];

    /** @var array<string, string> nome minúsculo => aba aberta */
    private array $aba = [];

    public function __construct(private RPGStatsPlugin $plugin) {}

    public function open(Player $player): void
    {
        $name = $player->getName();
        $manager = $this->plugin->getDataManager();

        if ($manager->getStats($name) === null) {
            $player->sendMessage("§cSeus dados ainda não foram carregados.");
            return;
        }

        $aba = $this->aba[strtolower($name)] ?? self::ABA_FICHA;
        $amount = $this->amountFor($name);

        $dialogue = NpcDialogueBuilder::create();
        $dialogue->setName("rpgstats|{$aba}" . $manager->getUiPreset($name)->token());
        $dialogue->setText(match ($aba) {
            self::ABA_DETALHES => $this->textoDetalhes($player),
            self::ABA_BONUS => $this->textoBonus($player),
            default => "Toque num atributo para treinar +{$amount} com TP",
        });

        // 0 a 5: os cartões, na ordem de ATTRIBUTES.
        foreach (PlayerDataManager::ATTRIBUTES as $stat) {
            $dialogue->addSimpleButton(
                $this->cartao($name, $stat, $amount),
                function (Player $p) use ($stat): void {
                    $this->buy($p, $stat);
                },
            );
        }

        // 6: fechar.
        $dialogue->addSimpleButton("Fechar", function (Player $p): void {
            $this->close($p);
        });

        // 7, 8, 9: menos, quantidade, mais.
        $dialogue->addSimpleButton("-", function (Player $p): void {
            $this->cycle($p, -1);
        });
        $dialogue->addSimpleButton("{$amount}x");
        $dialogue->addSimpleButton("+", function (Player $p): void {
            $this->cycle($p, 1);
        });

        // 10, 11, 12: as abas.
        foreach ([self::ABA_FICHA => "Atributos", self::ABA_DETALHES => "Detalhes", self::ABA_BONUS => "Bônus"] as $id => $rotulo) {
            $dialogue->addSimpleButton($rotulo, function (Player $p) use ($id): void {
                $this->trocaAba($p, $id);
            });
        }

        // 13 a 19: só texto, o pack não desenha esses como botão.
        foreach ($this->cabecalho($player) as $texto) {
            $dialogue->addSimpleButton($texto);
        }

        NpcDialogueManager::send($player, $dialogue->build(), true);
    }

    /**
     * Texto de um cartão: valor e nome em largura fixa, depois custo e efeito.
     */
    private function cartao(string $name, string $stat, int $amount): string
    {
        $manager = $this->plugin->getDataManager();

        $cost = $manager->getTrainingCostFor($name, $stat, $amount);
        $corCusto = $manager->getTp($name) >= $cost ? "§e" : "§c";

        return self::campo(self::compacto($manager->getStat($name, $stat)), self::LARGURA_VALOR)
            . self::campo($this->attributeName($stat), self::LARGURA_NOME)
            . $corCusto . self::compacto($cost) . " TP\n§7" . $this->efeito($name, $stat);
    }

    /** O que o atributo vale hoje, curto o bastante para caber no cartão. */
    private function efeito(string $name, string $stat): string
    {
        $manager = $this->plugin->getDataManager();

        return match ($stat) {
            "hp" => $manager->getMaxHealth($name) . " vida máx",
            "damage" => "+" . round($manager->getDamageBonus($name), 1) . " dano",
            "defense" => "-" . round($manager->getDamageReduction($name) * 100) . "% dano",
            "speed" => "vel. " . round($manager->getMovementSpeed($name), 3),
            "mana" => $manager->getMaxMana($name) . " mana máx",
            "atk_speed" => "-" . round($manager->getAttackSpeedBonus($name) * 100) . "% recarga",
            default => "",
        };
    }

    /**
     * Os botões 13 a 19, na ordem: nome, subtítulo, nível, XP em %, XP em texto,
     * pontos e TP.
     *
     * @return string[]
     */
    private function cabecalho(Player $player): array
    {
        $name = $player->getName();
        $manager = $this->plugin->getDataManager();
        $level = $manager->getLevel($name);

        $partes = [];
        $classe = $manager->getPlayerClass($name)?->name;
        $rank = $manager->getRank($name)?->name;
        $partes[] = $classe ?? "Sem classe";
        if ($rank !== null) {
            $partes[] = "Rank " . $rank;
        }

        if ($manager->isMaxLevel($name)) {
            $xpPct = 100;
            $xpTexto = "nível máximo";
        } else {
            $needed = $manager->getXpForLevel($level);
            $xp = $manager->getXp($name);
            $xpPct = $needed > 0 ? max(0, min(100, intdiv($xp * 100, $needed))) : 0;
            $xpTexto = self::compacto($xp) . " / " . self::compacto($needed) . " XP";
        }

        return [
            $player->getDisplayName(),
            implode(" · ", $partes),
            (string) $level,
            (string) $xpPct,
            $xpTexto,
            self::compacto($manager->getPoints($name)) . " pts",
            self::compacto($manager->getTp($name)) . " TP",
        ];
    }

    /** Aba de detalhes: os valores calculados e a origem de cada bônus. */
    private function textoDetalhes(Player $player): string
    {
        $name = $player->getName();
        $manager = $this->plugin->getDataManager();

        $titles = array_map(static fn($t): string => $t->name, $manager->getTitles($name));
        $linha = static fn(string $rotulo, string $valor): string => "§7{$rotulo}  §f{$valor}";

        return implode("\n", [
            "§lStatus calculados§r",
            $linha("Vida máxima", (string) $manager->getMaxHealth($name)),
            $linha("Mana", $manager->getMana($name) . " / " . $manager->getMaxMana($name)),
            $linha("Dano bônus", "+" . round($manager->getDamageBonus($name), 2)),
            $linha("Defesa", round($manager->getDefense($name), 1) . " §7(" . round($manager->getDamageReduction($name) * 100, 1) . "% do dano)"),
            $linha("Velocidade", (string) round($manager->getMovementSpeed($name), 3)),
            $linha("Recarga do ataque", "-" . round($manager->getAttackSpeedBonus($name) * 100, 1) . "%"),
            "",
            "§lProgresso§r",
            $linha("Nível", (string) $manager->getLevel($name)),
            $linha("Pontos", $manager->getPoints($name) . " §7· §fTP " . $manager->getTp($name)),
            $linha("Classe", $manager->getPlayerClass($name)?->name ?? "nenhuma"),
            $linha("Rank", $manager->getRank($name)?->name ?? "nenhum"),
            $linha("Títulos", $titles === [] ? "nenhum" : implode(", ", $titles)),
        ]);
    }

    /** Aba de bônus: classe, rank e títulos, cada um com o que soma. */
    private function textoBonus(Player $player): string
    {
        $name = $player->getName();
        $manager = $this->plugin->getDataManager();
        $linhas = [];

        foreach ([["Classe", $manager->getPlayerClass($name)], ["Rank", $manager->getRank($name)]] as [$rotulo, $mod]) {
            $linhas[] = "§l{$rotulo}§r  §f" . ($mod?->name ?? "§7nenhum");

            foreach ($mod?->describeBonuses() ?? [] as $bonus) {
                $linhas[] = "  " . $bonus;
            }

            $linhas[] = "";
        }

        $titles = $manager->getTitles($name);
        $linhas[] = "§lTítulos§r" . ($titles === [] ? "  §7nenhum" : "");

        foreach ($titles as $title) {
            $linhas[] = "§f" . $title->name;

            foreach ($title->describeBonuses() as $bonus) {
                $linhas[] = "  " . $bonus;
            }
        }

        return implode("\n", $linhas);
    }

    private function trocaAba(Player $player, string $aba): void
    {
        $this->aba[strtolower($player->getName())] = $aba;

        $this->open($player);
    }

    /** Nome do atributo no cartão. Só ASCII: vai num campo de largura fixa. */
    private function attributeName(string $stat): string
    {
        return match ($stat) {
            "hp" => "Vida",
            "damage" => "Dano",
            "defense" => "Defesa",
            "speed" => "Velocidade",
            "mana" => "Mana",
            "atk_speed" => "Vel. Ataque",
            default => strtoupper($stat),
        };
    }

    /** Compra com TP, na quantidade escolhida no seletor. */
    private function buy(Player $player, string $stat): void
    {
        $name = $player->getName();
        $manager = $this->plugin->getDataManager();

        $amount = $this->amountFor($name);
        $cost = $manager->getTrainingCostFor($name, $stat, $amount);
        $nome = $this->attributeName($stat);

        if (!$manager->spendTraining($name, $stat, $amount)) {
            $player->sendMessage(
                "§c{$amount}x de §f{$nome} §ccusta §f{$cost} §cTP e você tem §f" .
                $manager->getTp($name) . "§c."
            );

            // Fecha em vez de reabrir: a tela ficaria igual e pareceria travada.
            $this->close($player);

            return;
        }

        $player->sendMessage(
            "§a+{$amount} §7em §f{$nome} §7por §e{$cost} §7TP " .
            "(restam §e" . $manager->getTp($name) . "§7)."
        );

        $this->open($player);
    }

    /** Troca a quantidade e redesenha, que é reenviar o diálogo inteiro. */
    private function cycle(Player $player, int $direction): void
    {
        $key = strtolower($player->getName());
        $total = count(self::AMOUNTS);

        $this->choice[$key] = ((($this->choice[$key] ?? 0) + $direction) % $total + $total) % $total;

        $this->open($player);
    }

    private function amountFor(string $name): int
    {
        return self::AMOUNTS[$this->choice[strtolower($name)] ?? 0];
    }

    private function close(Player $player): void
    {
        NpcDialogueManager::remove($player);
    }

    /**
     * Campo de largura fixa para o pack cortar. Corta o excesso em vez de vazar para o
     * campo seguinte, e nunca deixa "@" de dentro do texto confundir o enchimento.
     */
    private static function campo(string $texto, int $largura): string
    {
        $texto = substr(str_replace("@", "", $texto), 0, $largura);

        return str_pad($texto, $largura, "@");
    }

    /** Número curto: 999999 cabe inteiro, acima disso vira 1.2M, 34B... */
    private static function compacto(int $n): string
    {
        if (abs($n) < 1_000_000) {
            return (string) $n;
        }

        foreach ([1_000_000_000 => "B", 1_000_000 => "M"] as $base => $sufixo) {
            if (abs($n) >= $base) {
                $v = $n / $base;

                return (abs($v) < 10 ? number_format($v, 1, ".", "") : (string) (int) $v) . $sufixo;
            }
        }

        return (string) $n;
    }
}
