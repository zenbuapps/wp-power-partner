import { identityAtom, globalLoadingAtom } from '@/pages/UserApp/atom'
import { useAtomValue, useSetAtom } from 'jotai'
import { useEffect, useState } from 'react'
import { currentUserId } from '@/utils'
import { DataType, TParams } from '@/components/LicenseCodes/types'
import { LicenseCodes } from '@/components'
import { useTable } from '@/hooks'

const index = () => {
	const identity = useAtomValue(identityAtom)
	const partner_id = identity.data?.partner_id || ''

	const setGlobalLoading = useSetAtom(globalLoadingAtom)
	const [search, setSearch] = useState('')

	// 不帶 author：本站 proxy 一律以 partner_id 覆寫；網址帶數字 author 會被 Wordfence 的「防止 /?author=N 列舉」擋成 404
	const { tableProps } = useTable<TParams, DataType>({
		resource: 'license-codes',
		defaultParams: {
			customer_id: currentUserId,
			search,
		},
		queryOptions: {
			enabled: !!partner_id && !!currentUserId,
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

	return <LicenseCodes tableProps={tableProps} setSearch={setSearch} />
}

export default index
