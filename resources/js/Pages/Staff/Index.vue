<script setup>
import { Head } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Table from '@/Components/UI/Table.vue';
import Badge from '@/Components/UI/Badge.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps({
    staff: { type: Object, required: true },
});

const formatWhen = (iso) =>
    iso ? new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) : 'Never';
</script>

<template>
    <Head title="Staff" />

    <StaffLayout>
        <PageHeader
            title="Staff"
            description="People who have signed in through IMIS. Roles are granted in IMIS, not here."
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Staff' }]"
        />

        <Table :empty="staff.data.length === 0">
            <template #empty>
                <EmptyState icon="users" title="No one has signed in yet" />
            </template>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Role</th>
                    <th scope="col">Office</th>
                    <th scope="col">Last sign-in</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="person in staff.data" :key="person.id">
                    <td>
                        {{ person.name }}
                        <div class="text-xs text-text-muted">{{ person.email ?? person.username }}</div>
                    </td>
                    <td><Badge>{{ person.role }}</Badge></td>
                    <td>{{ person.office ?? "-" }}</td>
                    <td class="whitespace-nowrap">{{ formatWhen(person.last_login_at) }}</td>
                </tr>
            </tbody>
        </Table>

        <Pagination
            :prev-url="staff.prev_page_url"
            :next-url="staff.next_page_url"
            :current-page="staff.current_page"
            :last-page="staff.last_page"
        />
    </StaffLayout>
</template>
