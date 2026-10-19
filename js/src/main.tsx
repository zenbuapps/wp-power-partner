import React from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ReactQueryDevtools } from '@tanstack/react-query-devtools'
import { app1Selector, app2Selector, LOCALSTORAGE_ACCOUNT_KEY } from '@/utils'

// 舊版把經銷商帳密以可逆密文（公開 passphrase）存在 localStorage，
// 新版改由 PHP 保管，開機時清掉殘留；存取 localStorage 可能被瀏覽器封鎖而拋錯
try {
	window.localStorage.removeItem(LOCALSTORAGE_ACCOUNT_KEY)
} catch {
	// 無法存取 localStorage 時也不會有殘留可清
}

const App1 = React.lazy(() => import('./App1'))
const App2 = React.lazy(() => import('./App2'))

const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			refetchOnWindowFocus: false,
			retry: 0,
		},
	},
})

const id1s = document.querySelectorAll(app1Selector)
const id2s = document.querySelectorAll(app2Selector)

const mapping = [
	{
		els: id1s,
		App: App1,
	},
	{
		els: id2s,
		App: App2,
	},
]

document.addEventListener('DOMContentLoaded', () => {
	mapping.forEach(({ els, App }) => {
		if (!!els) {
			els.forEach((el) => {
				createRoot(el).render(
					<React.StrictMode>
						<QueryClientProvider client={queryClient}>
							<App />
							<ReactQueryDevtools initialIsOpen={true} />
						</QueryClientProvider>
					</React.StrictMode>,
				)
			})
		}
	})
})
