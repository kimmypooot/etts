<script setup>
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';

/**
 * A board row's buttons, exactly as the server lists them
 * (App\Services\Transactions\TransactionActions): links open a page, the
 * others ask the parent to open the action dialog, and Logs/History opens the
 * record's history window.
 */
defineProps({
    row: { type: Object, required: true },
});

const emit = defineEmits(['act', 'history']);
</script>

<template>
    <div class="flex flex-wrap items-center justify-end gap-1.5">
        <template v-for="action in row.actions" :key="action.key">
            <Button
                v-if="action.kind === 'history'"
                variant="ghost"
                size="sm"
                :aria-label="`Logs and history of transaction ${row.id}`"
                title="Logs/History"
                icon-only
                @click="emit('history', row)"
            >
                <Icon name="clock" size="sm" />
            </Button>
            <Button v-else-if="action.kind === 'link'" :variant="action.variant" size="sm" :href="action.href">{{ action.label }}</Button>
            <Button v-else :variant="action.variant" size="sm" @click="emit('act', row, action)">{{ action.label }}</Button>
        </template>
    </div>
</template>
