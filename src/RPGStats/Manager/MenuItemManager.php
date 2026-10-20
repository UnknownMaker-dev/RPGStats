<?php

namespace RPGStats\Manager;

use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use RPGStats\RPGStatsPlugin;

/**
 * O item que abre a ficha, preso num slot fixo da hotbar.
 *
 * A trava tem duas camadas, e as duas são necessárias:
 *
 * - **No cliente**, a tag `minecraft:item_lock` faz o Bedrock recusar mover o item
 *   (é o "lock_in_slot" do jogo, com o cadeado no slot). Isso poupa o vai-e-volta
 *   de o jogador arrastar o item e vê-lo voltar.
 * - **No servidor**, o `MenuItemListener` cancela qualquer transação que toque no item,
 *   e um listener no inventário devolve o item ao slot se algo o tirar de lá por fora
 *   de uma transação (`/clear`, outro plugin, morte com o inventário limpo).
 *
 * O item é reconhecido pela tag `rpgstats:menu`, não pelo nome nem pelo tipo: o nome sai
 * do config e muda, e um item igual sem a tag é só um item comum.
 */
class MenuItemManager
{
    public const TAG = "rpgstats:menu";

    /** Tag do Bedrock para itens travados. 1 = preso no slot, 2 = preso no inventário. */
    private const TAG_LOCK = "minecraft:item_lock";
    private const LOCK_IN_SLOT = 1;

    /** Ticks entre dois usos. O cliente manda vários cliques seguidos ao segurar o botão. */
    private const USE_COOLDOWN_TICKS = 10;

    private bool $enabled;
    private int $slot;
    private Item $template;

    /** @var array<string, CallbackInventoryListener> nome minúsculo => listener do inventário */
    private array $listeners = [];

    /** @var array<string, true> nome minúsculo => devolução já agendada */
    private array $pending = [];

    /** @var array<string, int> nome minúsculo => tick do último uso */
    private array $lastUse = [];

    public function __construct(private RPGStatsPlugin $plugin)
    {
        $config = $plugin->getConfig();

        $this->enabled = (bool) $config->getNested("item-menu.enabled", true);
        $this->slot = max(0, min(8, (int) $config->getNested("item-menu.slot", 8)));

        $id = (string) $config->getNested("item-menu.item", "nether_star");
        $item = StringToItemParser::getInstance()->parse($id);

        if ($item === null) {
            $plugin->getLogger()->warning("item-menu.item \"{$id}\" não é um item válido; usando nether_star.");
            $item = VanillaItems::NETHER_STAR();
        }

        $item->setCount(1);
        $item->setCustomName((string) $config->getNested("item-menu.nome", "§r§6§lMenu"));
        $item->setLore(array_map("strval", (array) $config->getNested("item-menu.lore", [])));
        $item->setKeepOnDeath(true);
        $item->getNamedTag()->setByte(self::TAG, 1);
        $item->getNamedTag()->setByte(self::TAG_LOCK, self::LOCK_IN_SLOT);

        $this->template = $item;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSlot(): int
    {
        return $this->slot;
    }

    public function isMenu(Item $item): bool
    {
        return !$item->isNull() && $item->getNamedTag()->getTag(self::TAG) !== null;
    }

    /**
     * Põe o item no slot e passa a vigiar o inventário.
     *
     * Com o item desligado no config, faz o contrário: tira os que sobraram de quando
     * estava ligado, para ninguém ficar com um item travado que não faz nada.
     */
    public function attach(Player $player): void
    {
        if (!$this->enabled) {
            $this->removeAll($player);
            return;
        }

        $this->ensure($player);

        $key = strtolower($player->getName());

        if (isset($this->listeners[$key])) {
            return;
        }

        // Só reage quando o slot do menu deixou de ter o menu. A devolução vai para o
        // próximo tick porque mexer no inventário dentro do próprio aviso de mudança
        // atropelaria quem está mudando (um setContents no meio do laço, por exemplo).
        $listener = new CallbackInventoryListener(
            function (Inventory $inventory, int $slot, Item $oldItem) use ($player): void {
                if ($slot === $this->slot && !$this->isMenu($inventory->getItem($slot))) {
                    $this->scheduleEnsure($player);
                }
            },
            function (Inventory $inventory, array $oldContents) use ($player): void {
                if (!$this->isMenu($inventory->getItem($this->slot))) {
                    $this->scheduleEnsure($player);
                }
            },
        );

        $player->getInventory()->getListeners()->add($listener);
        $this->listeners[$key] = $listener;
    }

    /**
     * Garante o item no slot, e só lá.
     *
     * Quem estava ocupando o slot vai para outro lugar do inventário; sem espaço, cai no
     * chão aos pés do jogador. O item nunca some.
     */
    public function ensure(Player $player): void
    {
        if (!$this->enabled || !$player->isConnected()) {
            return;
        }

        $inventory = $player->getInventory();

        // Cópias fora do slot: sobra de quando o slot era outro no config.
        foreach ($inventory->getContents() as $index => $item) {
            if ($index !== $this->slot && $this->isMenu($item)) {
                $inventory->clear($index);
            }
        }

        $current = $inventory->getItem($this->slot);

        if ($current->equals($this->template, true, true)) {
            return;
        }

        $inventory->setItem($this->slot, clone $this->template);

        // Um menu antigo (nome ou lore de outro config) só é trocado, não devolvido.
        if ($current->isNull() || $this->isMenu($current)) {
            return;
        }

        foreach ($inventory->addItem($current) as $leftover) {
            $player->getWorld()->dropItem($player->getPosition(), $leftover);
        }
    }

    public function canUse(Player $player): bool
    {
        $key = strtolower($player->getName());
        $tick = $this->plugin->getServer()->getTick();

        if ($tick - ($this->lastUse[$key] ?? -self::USE_COOLDOWN_TICKS) < self::USE_COOLDOWN_TICKS) {
            return false;
        }

        $this->lastUse[$key] = $tick;

        return true;
    }

    public function forget(Player $player): void
    {
        $key = strtolower($player->getName());

        if (isset($this->listeners[$key])) {
            $player->getInventory()->getListeners()->remove($this->listeners[$key]);
        }

        unset($this->listeners[$key], $this->pending[$key], $this->lastUse[$key]);
    }

    /** Solta todos os inventários vigiados; um reload criaria um segundo listener por cima. */
    public function forgetAll(): void
    {
        foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
            $this->forget($player);
        }
    }

    private function scheduleEnsure(Player $player): void
    {
        $key = strtolower($player->getName());

        if (isset($this->pending[$key])) {
            return;
        }

        $this->pending[$key] = true;

        $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($player, $key): void {
            unset($this->pending[$key]);
            $this->ensure($player);
        }), 1);
    }

    private function removeAll(Player $player): void
    {
        foreach ([$player->getInventory(), $player->getOffHandInventory(), $player->getCursorInventory()] as $inventory) {
            foreach ($inventory->getContents() as $index => $item) {
                if ($this->isMenu($item)) {
                    $inventory->clear($index);
                }
            }
        }
    }
}
