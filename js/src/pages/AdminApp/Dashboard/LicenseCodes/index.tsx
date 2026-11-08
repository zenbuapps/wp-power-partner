import { identityAtom, globalLoadingAtom } from '@/pages/AdminApp/Atom/atom'
import { useAtomValue, useSetAtom } from 'jotai'
import { useEffect, useState } from 'react'
import { currentUserId } from '@/utils'
import { DataType, TParams } from '@/components/LicenseCodes/types'
import { LicenseCodes } from '@/components'
import { useTable } from '@/hooks'
import ContentCard from '@/components/ContentCard'

const index = () => {
	const identity = useAtomValue(identityAtom)
	const user_id = identity.data?.user_id || ''

	const setGlobalLoading = useSetAtom(globalLoadingAtom)
	const [search, setSearch] = useState('')

	// 不帶 author：本站 proxy 一律以 partner_id 覆寫；網址帶數字 author 會被 Wordfence 的「防止 /?author=N 列舉」擋成 404
	const { tableProps } = useTable<TParams, DataType>({
		resource: 'license-codes',
		defaultParams: {
			search,
		},
		queryOptions: {
			enabled: !!user_id && !!currentUserId,
			staleTime: 1000 * 60 * 60 * 24,
			gcTime: 1000 * 60 * 60 * 24,
		},
	})

	useEffect(() => {
		if (!tableProps?.loading) {
			setGlobalLoading({
				isLoading: false,
				label: '',
			})
		}
	}, [tableProps?.loading])

	return (
		<ContentCard>
			<LicenseCodes tableProps={tableProps} setSearch={setSearch} isAdmin />
		</ContentCard>
	)
}

export default index
