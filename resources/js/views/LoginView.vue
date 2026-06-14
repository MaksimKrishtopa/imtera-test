<template>
  <div class="login-page">
    <div class="login-card">
      <div class="login-header">
        <div class="logo">
          <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
            <rect width="32" height="32" rx="8" fill="#FC3F1D"/>
            <path d="M18.4 8H14.6C11.5 8 9.6 9.7 9.6 12.4C9.6 14.8 10.8 16.2 13 17.5L9.2 24H12.8L16.4 17.9H17.2V24H20.4V8H18.4ZM17.2 15.3H16C14.2 15.3 13.1 14.5 13.1 12.5C13.1 10.6 14.2 9.8 16.1 9.8H17.2V15.3Z" fill="white"/>
          </svg>
        </div>
        <h1>Яндекс.Карты</h1>
        <p>Вход в систему управления отзывами</p>
      </div>

      <form @submit.prevent="handleLogin" class="login-form">
        <div class="field">
          <label for="email">Email</label>
          <input
            id="email"
            v-model="email"
            type="email"
            placeholder="admin@imtera.test"
            autocomplete="email"
            :disabled="loading"
            required
          />
        </div>

        <div class="field">
          <label for="password">Пароль</label>
          <input
            id="password"
            v-model="password"
            type="password"
            placeholder="••••••••"
            autocomplete="current-password"
            :disabled="loading"
            required
          />
        </div>

        <div v-if="errorMsg" class="error-alert">
          {{ errorMsg }}
        </div>

        <button type="submit" class="btn-primary" :disabled="loading">
          <span v-if="loading" class="spinner-sm"></span>
          {{ loading ? 'Вход...' : 'Войти' }}
        </button>

        <div class="hint">
          <span>Demo: admin@imtera.test / password</span>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const router = useRouter();
const auth = useAuthStore();

const email = ref('admin@imtera.test');
const password = ref('');
const loading = ref(false);
const errorMsg = ref('');

async function handleLogin() {
  errorMsg.value = '';
  loading.value = true;
  try {
    await auth.login(email.value, password.value);
    router.push('/dashboard');
  } catch (e) {
    errorMsg.value = e.response?.data?.message
      || e.response?.data?.errors?.email?.[0]
      || 'Неверный email или пароль';
  } finally {
    loading.value = false;
  }
}
</script>
