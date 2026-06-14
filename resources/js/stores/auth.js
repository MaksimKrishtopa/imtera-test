import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import api from '@/api/axios';

export const useAuthStore = defineStore('auth', () => {
    const user = ref(null);
    const token = ref(localStorage.getItem('auth_token'));

    const isAuthenticated = computed(() => !!token.value);

    async function login(email, password) {
        const { data } = await api.post('/auth/login', { email, password });
        token.value = data.token;
        user.value = data.user;
        localStorage.setItem('auth_token', data.token);
    }

    async function logout() {
        try {
            await api.post('/auth/logout');
        } finally {
            token.value = null;
            user.value = null;
            localStorage.removeItem('auth_token');
        }
    }

    async function fetchMe() {
        if (!token.value) return;
        try {
            const { data } = await api.get('/auth/me');
            user.value = data;
        } catch {
            token.value = null;
            localStorage.removeItem('auth_token');
        }
    }

    return { user, token, isAuthenticated, login, logout, fetchMe };
});
