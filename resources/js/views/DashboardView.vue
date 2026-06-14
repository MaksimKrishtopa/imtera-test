<template>
  <div class="dashboard">
    <header class="app-header">
      <div class="header-inner">
        <div class="header-brand">
          <svg width="28" height="28" viewBox="0 0 32 32" fill="none">
            <rect width="32" height="32" rx="8" fill="#FC3F1D"/>
            <path d="M18.4 8H14.6C11.5 8 9.6 9.7 9.6 12.4C9.6 14.8 10.8 16.2 13 17.5L9.2 24H12.8L16.4 17.9H17.2V24H20.4V8H18.4ZM17.2 15.3H16C14.2 15.3 13.1 14.5 13.1 12.5C13.1 10.6 14.2 9.8 16.1 9.8H17.2V15.3Z" fill="white"/>
          </svg>
          <span>Отзывы с Яндекс.Карт</span>
        </div>
        <div class="header-user">
          <span class="user-name">{{ auth.user?.name }}</span>
          <button @click="handleLogout" class="btn-logout">Выйти</button>
        </div>
      </div>
    </header>

    <main class="main-content">
      <!-- Settings Section -->
      <section class="settings-card">
        <h2>Настройки организации</h2>
        <p class="section-desc">Вставьте ссылку на карточку вашей организации в Яндекс.Картах</p>

        <form @submit.prevent="handleSave" class="url-form">
          <div class="url-input-group">
            <input
              v-model="urlInput"
              type="url"
              placeholder="https://yandex.ru/maps/org/название/1234567890/"
              class="url-input"
              :disabled="orgStore.loading || orgStore.parsing"
            />
            <button
              type="submit"
              class="btn-primary"
              :disabled="orgStore.loading || orgStore.parsing || !urlInput"
            >
              Сохранить
            </button>
          </div>
          <div v-if="saveError" class="error-alert">{{ saveError }}</div>
          <div v-if="saveSuccess" class="success-alert">Ссылка сохранена</div>
        </form>

        <!-- Org info and parse button -->
        <div v-if="org" class="org-section">
          <div class="org-meta">
            <div v-if="org.name" class="org-name">{{ org.name }}</div>
            <div class="org-url-display">{{ org.url }}</div>
          </div>

          <div v-if="org.parse_status === 'done'" class="stats-row">
            <div class="stat-card">
              <div class="stat-value">{{ org.rating?.toFixed(1) ?? '—' }}</div>
              <div class="stat-label">Средний рейтинг</div>
              <div class="stars">
                <span v-for="n in 5" :key="n" :class="['star', { filled: n <= Math.round(org.rating) }]">★</span>
              </div>
            </div>
            <div class="stat-card">
              <div class="stat-value">{{ org.reviews_count ?? '—' }}</div>
              <div class="stat-label">Отзывов</div>
            </div>
            <div class="stat-card">
              <div class="stat-value">{{ org.ratings_count ?? '—' }}</div>
              <div class="stat-label">Оценок</div>
            </div>
            <div class="stat-card">
              <div class="stat-value">{{ localReviewsCount }}</div>
              <div class="stat-label">Загружено</div>
            </div>
          </div>

          <div class="parse-section">
            <div v-if="org.parse_status === 'done' && org.parsed_at" class="parsed-at">
              Последнее обновление: {{ formatDate(org.parsed_at) }}
            </div>
            <div v-if="org.parse_status === 'error'" class="error-alert">
              Ошибка: {{ org.parse_error }}
            </div>

            <button
              @click="handleParse"
              class="btn-parse"
              :disabled="orgStore.parsing || org.parse_status === 'processing'"
            >
              <span v-if="orgStore.parsing || org.parse_status === 'processing'" class="spinner-sm"></span>
              <span v-if="orgStore.parsing || org.parse_status === 'processing'">Загружаем отзывы...</span>
              <span v-else-if="org.parse_status === 'done'">Обновить отзывы</span>
              <span v-else>Загрузить отзывы</span>
            </button>
          </div>
        </div>
      </section>

      <!-- Reviews Section -->
      <section v-if="org?.parse_status === 'done'" class="reviews-section">
        <div class="reviews-header">
          <h2>Отзывы <span class="reviews-count">{{ totalReviews }}</span></h2>
        </div>

        <div v-if="reviewsLoading" class="loading-state">
          <div class="spinner"></div>
          <p>Загружаем отзывы...</p>
        </div>

        <div v-else-if="reviewsError" class="error-alert">{{ reviewsError }}</div>

        <div v-else>
          <div class="reviews-list">
            <ReviewCard v-for="review in reviews" :key="review.id" :review="review" />
          </div>

          <Pagination
            v-if="lastPage > 1"
            :current-page="currentPage"
            :last-page="lastPage"
            @change="goToPage"
          />
        </div>
      </section>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useOrganizationStore } from '@/stores/organization';
import ReviewCard from '@/components/ReviewCard.vue';
import Pagination from '@/components/Pagination.vue';
import api from '@/api/axios';

const router = useRouter();
const auth = useAuthStore();
const orgStore = useOrganizationStore();

const org = computed(() => orgStore.organization);
const urlInput = ref('');
const saveError = ref('');
const saveSuccess = ref(false);

const reviews = ref([]);
const totalReviews = ref(0);
const localReviewsCount = ref(0);
const currentPage = ref(1);
const lastPage = ref(1);
const reviewsLoading = ref(false);
const reviewsError = ref('');

onMounted(async () => {
  await orgStore.fetch();
  if (org.value) {
    urlInput.value = org.value.url;
    if (org.value.parse_status === 'done') {
      await loadReviews(1);
    }
  }
});

async function handleSave() {
  saveError.value = '';
  saveSuccess.value = false;
  try {
    await orgStore.save(urlInput.value);
    saveSuccess.value = true;
    setTimeout(() => { saveSuccess.value = false; }, 3000);
  } catch (e) {
    saveError.value = e.message;
  }
}

async function handleParse() {
  try {
    await orgStore.parse();
    await loadReviews(1);
  } catch (e) {
    // error shown in org section
  }
}

async function loadReviews(page) {
  reviewsLoading.value = true;
  reviewsError.value = '';
  try {
    const { data } = await api.get('/reviews', { params: { page } });
    reviews.value = data.data;
    totalReviews.value = data.total;
    localReviewsCount.value = data.total;
    currentPage.value = data.current_page;
    lastPage.value = data.last_page;
  } catch (e) {
    reviewsError.value = e.response?.data?.message || 'Ошибка загрузки отзывов';
  } finally {
    reviewsLoading.value = false;
  }
}

async function goToPage(page) {
  await loadReviews(page);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function handleLogout() {
  await auth.logout();
  router.push('/');
}

function formatDate(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleString('ru-RU', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}
</script>
