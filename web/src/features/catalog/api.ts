import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '../../lib/api';
import { toSearch, type CatalogQuery } from './urlState';

export interface Category {
  id: number;
  slug: string;
  name: string;
}
export interface Product {
  id: number;
  part_number: string;
  name: string;
  brand: string;
  category: Category;
  inner_diameter_mm: number;
  outer_diameter_mm: number;
  width_mm: number;
  material: string;
  seal_type: string;
  price_cents: number | null;
  price_on_request: boolean;
  stock_status: string;
}
export type ProductDetail = Product & { description: string | null };
export interface ProductList {
  data: Product[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
}
interface Range {
  min: number | null;
  max: number | null;
}
export interface Filters {
  currency: string;
  brands: string[];
  materials: string[];
  seal_types: string[];
  stock_statuses: string[];
  ranges: Record<'inner_diameter_mm' | 'outer_diameter_mm' | 'width_mm', Range>;
}

const MIN = 60_000;

export const useProducts = (query: CatalogQuery) => {
  const search = toSearch(query); // per_page omitted: API default is 24
  return useQuery({
    queryKey: ['catalog', 'products', search],
    queryFn: () => api.get<ProductList>(`/api/v1/products${search}`),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  });
};

export const useFilters = () =>
  useQuery({
    queryKey: ['catalog', 'filters'],
    queryFn: async () =>
      (await api.get<{ data: Filters }>('/api/v1/products/filters')).data,
    staleTime: 10 * MIN,
  });

export const useCategories = () =>
  useQuery({
    queryKey: ['catalog', 'categories'],
    queryFn: async () =>
      (
        await api.get<{ data: (Category & { product_count: number })[] }>(
          '/api/v1/categories',
        )
      ).data,
    staleTime: 10 * MIN,
  });

export const useProduct = (id: string, enabled: boolean) =>
  useQuery({
    queryKey: ['catalog', 'product', id],
    queryFn: async () =>
      (await api.get<{ data: ProductDetail }>(`/api/v1/products/${id}`)).data,
    enabled,
  });
