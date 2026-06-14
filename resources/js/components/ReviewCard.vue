<template>
  <div class="review-card">
    <div class="review-header">
      <div class="author-info">
        <div class="author-avatar">
          <img
            v-if="review.author_avatar"
            :src="review.author_avatar"
            :alt="review.author_name"
            @error="avatarError = true"
          />
          <span v-else class="avatar-initials">{{ initials }}</span>
        </div>
        <div class="author-details">
          <div class="author-name">{{ review.author_name }}</div>
          <div class="review-date">{{ formatDate(review.reviewed_at) }}</div>
        </div>
      </div>

      <div v-if="review.rating" class="review-rating">
        <span v-for="n in 5" :key="n" :class="['star', { filled: n <= review.rating }]">★</span>
        <span class="rating-num">{{ review.rating }}</span>
      </div>
    </div>

    <div v-if="review.text" class="review-text">
      <p :class="{ truncated: !expanded && review.text.length > 300 }">
        {{ expanded || review.text.length <= 300 ? review.text : review.text.slice(0, 300) + '...' }}
      </p>
      <button
        v-if="review.text.length > 300"
        @click="expanded = !expanded"
        class="expand-btn"
      >
        {{ expanded ? 'Свернуть' : 'Читать полностью' }}
      </button>
    </div>
    <div v-else class="review-no-text">Без текста</div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  review: {
    type: Object,
    required: true,
  },
});

const expanded = ref(false);
const avatarError = ref(false);

const initials = computed(() => {
  const name = props.review.author_name || 'А';
  return name.charAt(0).toUpperCase();
});

function formatDate(iso) {
  if (!iso) return '';
  const date = new Date(iso);
  return date.toLocaleDateString('ru-RU', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  });
}
</script>
