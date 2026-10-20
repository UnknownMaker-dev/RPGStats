<?php

namespace RPGStats\Listener;

use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerBlockPickEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerEntityPickEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\player\Player;
use RPGStats\Manager\MenuItemManager;
use RPGStats\RPGStatsPlugin;

/**
 * Abre a ficha com o item de menu e impede que ele saia do slot.
 *
 * Tudo que mexe no item passa por uma transação de inventário — arrastar, trocar de
 * slot, jogar fora, apagar no inventário do criativo, guardar num baú. Cancelar a
 * transação inteira quando ela toca no item cobre esses casos de uma vez.
 */
class MenuItemListener implements Listener
{
    public function __construct(
        private RPGStatsPlugin $plugin,
        private MenuItemManager $menu,
    ) {
    }

    public function onJoin(PlayerJoinEvent $event): void
    {
        $this->menu->attach($event->getPlayer());
    }

    public function onRespawn(PlayerRespawnEvent $event): void
    {
        $this->menu->ensure($event->getPlayer());
    }

    public function onQuit(PlayerQuitEvent $event): void
    {
        $this->menu->forget($event->getPlayer());
    }

    public function onUse(PlayerItemUseEvent $event): void
    {
        if (!$this->menu->isMenu($event->getItem())) {
            return;
        }

        $event->cancel();
        $this->open($event->getPlayer());
    }

    /** Clicar num bloco não passa pelo PlayerItemUseEvent: no celular, é o toque mais comum. */
    public function onInteract(PlayerInteractEvent $event): void
    {
        if (!$this->menu->isMenu($event->getItem())) {
            return;
        }

        if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
            return;
        }

        $event->cancel();
        $this->open($event->getPlayer());
    }

    /**
     * @priority LOWEST
     */
    public function onTransaction(InventoryTransactionEvent $event): void
    {
        $transaction = $event->getTransaction();
        $inventory = $transaction->getSource()->getInventory();

        foreach ($transaction->getActions() as $action) {
            $touchesMenu = $this->menu->isMenu($action->getSourceItem()) || $this->menu->isMenu($action->getTargetItem());

            // O slot do menu também não recebe nada, mesmo que o item já tenha saído de lá.
            $touchesSlot = $action instanceof SlotChangeAction
                && $action->getInventory() === $inventory
                && $action->getSlot() === $this->menu->getSlot();

            if ($touchesMenu || ($this->menu->isEnabled() && $touchesSlot)) {
                $event->cancel();
                return;
            }
        }
    }

    /**
     * @priority LOWEST
     */
    public function onDrop(PlayerDropItemEvent $event): void
    {
        if ($this->menu->isMenu($event->getItem())) {
            $event->cancel();
        }
    }

    /**
     * O pick block do criativo troca o item da mão de lugar; com o menu na mão, ele sairia do slot.
     *
     * @priority LOWEST
     */
    public function onBlockPick(PlayerBlockPickEvent $event): void
    {
        if ($this->menu->isMenu($event->getPlayer()->getInventory()->getItemInHand())) {
            $event->cancel();
        }
    }

    /**
     * @priority LOWEST
     */
    public function onEntityPick(PlayerEntityPickEvent $event): void
    {
        if ($this->menu->isMenu($event->getPlayer()->getInventory()->getItemInHand())) {
            $event->cancel();
        }
    }

    /** Um menu no chão só existe por acidente; ninguém o pega, e ele some. */
    public function onPickup(EntityItemPickupEvent $event): void
    {
        if ($this->menu->isMenu($event->getItem())) {
            $event->cancel();
            $event->getOrigin()->flagForDespawn();
        }
    }

    private function open(Player $player): void
    {
        if ($this->menu->canUse($player)) {
            $this->plugin->openSheet($player);
        }
    }
}
