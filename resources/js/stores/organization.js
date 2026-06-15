import { defineStore } from 'pinia';
import { ref } from 'vue';
import api from '@/api/axios';

export const useOrganizationStore = defineStore('organization', () => {
    const organization = ref(null);
    const loading = ref(false);
    const parsing = ref(false);
    const error = ref(null);

    async function fetch() {
        loading.value = true;
        error.value = null;
        try {
            const { data } = await api.get('/organization');
            organization.value = data;
        } catch (e) {
            error.value = e.response?.data?.message || 'Ошибка загрузки данных';
        } finally {
            loading.value = false;
        }
    }

    async function save(url) {
        loading.value = true;
        error.value = null;
        try {
            const { data } = await api.post('/organization', { url });
            organization.value = data;
            return data;
        } catch (e) {
            const msg = e.response?.data?.errors?.url?.[0]
                || e.response?.data?.message
                || 'Ошибка сохранения';
            error.value = msg;
            throw new Error(msg);
        } finally {
            loading.value = false;
        }
    }

    async function parse() {
        parsing.value = true;
        error.value = null;
        try {
            const { data } = await api.post('/organization/parse');
            organization.value = data;
            return data;
        } catch (e) {
            if (e.response?.status === 409) {
                if (e.response?.data?.organization) {
                    organization.value = e.response.data.organization;
                }
                return organization.value;
            }
            const msg = e.response?.data?.message || 'Ошибка парсинга';
            error.value = msg;
            throw new Error(msg);
        } finally {
            parsing.value = false;
        }
    }

    return { organization, loading, parsing, error, fetch, save, parse };
});
