export interface IWebsite {
	id: string
	name: string
	domain?: string
	primaryDomain?: string
	subDomain?: string
	wildcardDomain: string
	namespace: string
	status: string
	adminUsername: string
	adminEmail: string
	adminPassword: string
	databaseName: string
	databaseUsername: string
	databasePassword: string | null
	databaseRootPassword: string | null
	package: {
		id: string
		name: string
		description: string
		price: string
		wordpressSize: string
		mysqlSize: string
	} | null
	user: {
		id: string
		firstName: string
		lastName: string
		email: string
	} | null
	phpPodSize: number
	ipAddress: string
	dailyCost?: number
	memo?: string
	createdAt: string
	updatedAt: string
}

export interface IWebsiteResponse {
	data: IWebsite[]
	total: number
}
