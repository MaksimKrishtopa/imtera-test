<template>
  <div class="pagination">
    <button
      @click="$emit('change', currentPage - 1)"
      :disabled="currentPage <= 1"
      class="page-btn"
    >
      ← Назад
    </button>

    <div class="page-numbers">
      <button
        v-for="page in visiblePages"
        :key="page"
        @click="page !== '...' && $emit('change', page)"
        :class="['page-btn', { active: page === currentPage, dots: page === '...' }]"
        :disabled="page === '...'"
      >
        {{ page }}
      </button>
    </div>

    <button
      @click="$emit('change', currentPage + 1)"
      :disabled="currentPage >= lastPage"
      class="page-btn"
    >
      Вперёд →
    </button>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  currentPage: { type: Number, required: true },
  lastPage: { type: Number, required: true },
});

defineEmits(['change']);

const visiblePages = computed(() => {
  const pages = [];
  const total = props.lastPage;
  const cur = props.currentPage;

  if (total <= 7) {
    for (let i = 1; i <= total; i++) pages.push(i);
    return pages;
  }

  pages.push(1);
  if (cur > 3) pages.push('...');
  for (let i = Math.max(2, cur - 1); i <= Math.min(total - 1, cur + 1); i++) {
    pages.push(i);
  }
  if (cur < total - 2) pages.push('...');
  pages.push(total);

  return pages;
});
</script>
